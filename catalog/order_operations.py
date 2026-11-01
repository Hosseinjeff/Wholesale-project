"""Owner-recorded supplier, customer-choice and delivery follow-up for Woo orders.

This ledger records decisions and factual confirmations. It does not contact
customers, submit supplier purchases, charge cards, or issue payment refunds.
"""
from __future__ import annotations

import hashlib
import json
import os
import sqlite3
from contextlib import contextmanager
from datetime import date

from catalog.inventory_lots import InventoryLots


class OrderOperationConflict(ValueError):
    pass


class OrderOperations:
    def __init__(self, path):
        self.path = os.path.realpath(os.fspath(path))
        with self._connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS order_workflows (
                order_id TEXT PRIMARY KEY, revision INTEGER NOT NULL, workflow TEXT NOT NULL,
                updated_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS order_workflow_events (
                event_id TEXT PRIMARY KEY, order_id TEXT NOT NULL, actor_id TEXT NOT NULL,
                action TEXT NOT NULL, details TEXT NOT NULL, note TEXT NOT NULL,
                created_at TEXT NOT NULL);''')
        os.chmod(self.path, 0o600)

    @contextmanager
    def _connect(self):
        db = sqlite3.connect(self.path, timeout=15)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    def ensure(self, order_id, plan, *, today):
        if not order_id or not isinstance(plan, dict):
            raise ValueError('order and fulfillment plan are required')
        open_shortages = any((line.get('shortfall_units') or 0) > 0 for line in plan.get('lines', []))
        workflow = {
            'order_id': str(order_id),
            'state': 'needs_owner_review' if plan.get('state') == 'needs_owner_review'
                else ('supplier_followup' if open_shortages else 'ready_to_pack'),
            'assigned_to': '',
            'next_action': 'Review supplier terms or unavailable lines' if open_shortages
                else 'Check and pack the complete order',
            'due_at': '',
            'procurement': {},
            'resolutions': {},
            'refunds': [],
            'settlements': [],
            'packing': {'status': 'pending'},
            'dispatch': {'status': 'pending'},
            'delivery': {'status': 'pending'},
            'aftercare': {'status': 'none'},
        }
        with self._connect() as db:
            db.execute('INSERT OR IGNORE INTO order_workflows VALUES (?,?,?,?)',
                       (str(order_id), 1, _encode(workflow), str(today)))
        return self.get(order_id)

    def get(self, order_id):
        with self._connect() as db:
            row = db.execute('SELECT workflow FROM order_workflows WHERE order_id=?',
                             (str(order_id),)).fetchone()
        return json.loads(row['workflow']) if row else None

    def list_workflows(self):
        with self._connect() as db:
            rows = db.execute('SELECT workflow FROM order_workflows ORDER BY updated_at,order_id').fetchall()
        return [json.loads(row['workflow']) for row in rows]

    def apply(self, *, order, plan, action, details, actor_id, note, today, inventory=None):
        order_id = str(order.get('id') or '')
        if not order_id or not isinstance(details, dict) or not actor_id or not str(note or '').strip():
            raise ValueError('order, action details, owner and note are required')
        workflow = self.ensure(order_id, plan, today=today)
        event = {'order_id': order_id, 'action': action, 'details': details,
                 'actor_id': actor_id, 'note': str(note).strip()[:1000]}
        event_id = hashlib.sha256(_encode(event).encode()).hexdigest()
        with self._connect() as db:
            prior = db.execute('SELECT 1 FROM order_workflow_events WHERE event_id=?',
                               (event_id,)).fetchone()
        if prior:
            return self.get(order_id)
        if workflow['state'] == 'cancelled_refund_pending' and action not in {
                'confirm_refund', 'mark_supplier_received', 'mark_supplier_unavailable'}:
            raise OrderOperationConflict('complete the recorded cancellation refund before changing this order')
        if workflow['state'] == 'cancelled' and action not in {'mark_supplier_received', 'mark_supplier_unavailable'}:
            raise OrderOperationConflict('cancelled order workflow cannot be changed')

        # Stock transitions happen before the audit write. They are idempotent, so a
        # retry after a process interruption safely completes the workflow event.
        if action == 'record_customer_choice' and details.get('choice') == 'replacement':
            self._validate_replacement_choice(order, workflow, details)
            self._reserve_replacement(order, plan, workflow, details, inventory, today)
        elif action == 'record_customer_choice' and details.get('choice') == 'full_cancellation':
            _validate_full_cancellation_refund(workflow, order, details)
            (inventory or InventoryLots(self.path)).release_order(order_id=order_id)
        elif action == 'mark_packed':
            self._assert_packable(plan, workflow)
            self._fulfill_reservations(order_id)
        elif action == 'mark_supplier_received':
            self._receive_supplier_units(order, plan, workflow, details, inventory, today)
            resolved = (workflow.get('resolutions', {}).get(str(details.get('sku') or '')) or {}).get('state') in {
                'partial_refund','replacement_accepted','full_cancellation'}
            if workflow['state'] not in {'cancelled_refund_pending', 'cancelled'} and not resolved:
                plan = _refresh_plan(self.path, order_id, today)

        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            prior = db.execute('SELECT revision,workflow FROM order_workflows WHERE order_id=?',
                               (order_id,)).fetchone()
            if not prior:
                raise OrderOperationConflict('order workflow disappeared')
            workflow = json.loads(prior['workflow'])
            self._transition(workflow, order, plan, action, details, actor_id, note, today)
            db.execute('INSERT INTO order_workflow_events VALUES (?,?,?,?,?,?,?)',
                       (event_id, order_id, actor_id, action, _encode(details),
                        str(note).strip()[:1000], str(today)))
            db.execute('UPDATE order_workflows SET revision=?,workflow=?,updated_at=? WHERE order_id=?',
                       (prior['revision'] + 1, _encode(workflow), str(today), order_id))
        return workflow

    def _transition(self, workflow, order, plan, action, details, actor_id, note, today):
        if workflow['state'] == 'closed':
            raise OrderOperationConflict('closed order workflow cannot be changed')
        if action == 'assign':
            workflow['assigned_to'] = _text(details.get('assigned_to'), 120)
            workflow['next_action'] = _text(details.get('next_action'), 300)
            workflow['due_at'] = _text(details.get('due_at'), 40)
        elif action in {'record_supplier_order', 'mark_supplier_unavailable'}:
            sku = _required(details, 'sku', 80)
            prior = workflow['procurement'].get(sku, {})
            if action == 'record_supplier_order':
                proposal = _procurement_line(plan, sku)
                if proposal.get('state') != 'proposed':
                    raise OrderOperationConflict('only a calculated supplier proposal can be recorded as ordered')
                resolution_state=(workflow.get('resolutions', {}).get(sku) or {}).get('state')
                if resolution_state not in {None,'withdrawn'}:
                    raise OrderOperationConflict('resolve the customer choice before ordering this item')
                packs = _positive(details.get('packs'))
                if packs != proposal['supplier_packs']:
                    raise OrderOperationConflict('purchase pack count must match the reviewed proposal')
                reference = _required(details, 'supplier_reference', 120)
                purchase_date = _required(details, 'purchase_date', 10)
                try:
                    purchased = date.fromisoformat(purchase_date)
                except ValueError as exc:
                    raise OrderOperationConflict('supplier purchase date must be an ISO date') from exc
                if purchased.isoformat() != purchase_date or purchased.isoformat() > str(today):
                    raise OrderOperationConflict('supplier purchase date is invalid or in the future')
                expected = _text(details.get('expected_at'), 40)
                prior.update({'state': 'ordered', 'supplier': proposal['supplier'],
                              'supplier_reference': reference, 'packs': packs,
                              'expected_supplier_units': proposal['supplier_units'],
                              'purchase_date': purchase_date, 'expected_at': expected})
            else:
                if prior.get('state') not in {'ordered', 'partially_received'}:
                    _procurement_line(plan, sku)
                prior.update({'state': 'unavailable'})
            workflow['procurement'][sku] = prior
            if workflow['state'] not in {'cancelled_refund_pending', 'cancelled'}:
                workflow['state'] = 'supplier_followup'
        elif action == 'mark_supplier_received':
            sku = _required(details, 'sku', 80)
            lot = _required(details, 'lot_id', 120)
            accepted = _positive(details.get('accepted_units'))
            procurement = workflow['procurement'].get(sku)
            if not procurement or procurement.get('state') not in {'ordered', 'partially_received', 'unavailable'}:
                raise OrderOperationConflict('record a supplier order before its receipt')
            was_unavailable = procurement.get('state') == 'unavailable'
            procurement['received_units'] = procurement.get('received_units', 0) + accepted
            procurement['receipt_lots'] = list(dict.fromkeys(procurement.get('receipt_lots', []) + [lot]))
            expected = procurement['expected_supplier_units']
            procurement['state'] = ('received' if procurement['received_units'] >= expected else 'unavailable') \
                if was_unavailable else ('received' if procurement['received_units'] >= expected else 'partially_received')
            workflow['procurement'][sku] = procurement
            resolved = (workflow.get('resolutions', {}).get(sku) or {}).get('state') in {
                'partial_refund','replacement_accepted','full_cancellation'}
            if workflow['state'] not in {'cancelled_refund_pending', 'cancelled'} and not resolved:
                workflow['state'] = 'ready_to_pack' if plan.get('state') == 'reserved' else 'supplier_followup'
        elif action == 'propose_substitution':
            sku = _required(details, 'sku', 80)
            replacement = _required(details, 'replacement_sku', 80)
            units = _positive(details.get('replacement_units'))
            total = _nonnegative(details.get('proposed_line_total_toman'))
            if (workflow.get('resolutions', {}).get(sku) or {}).get('state') == 'awaiting_customer':
                raise OrderOperationConflict('withdraw the current customer proposal before offering another')
            procurement_state = (workflow.get('procurement', {}).get(sku) or {}).get('state')
            if procurement_state in {'ordered', 'partially_received', 'received'}:
                raise OrderOperationConflict('an ordered supplier line cannot be replaced while its purchase is open')
            if _shortfall(plan, sku) <= 0:
                raise OrderOperationConflict('the selected order line has no current shortfall')
            workflow['resolutions'][sku] = {
                'state': 'awaiting_customer', 'choice': '',
                'proposal': {'replacement_sku': replacement, 'replacement_units': units,
                             'proposed_line_total_toman': total, 'offered_at': str(today)},
            }
            workflow['state'] = 'awaiting_customer'
        elif action == 'withdraw_substitution':
            sku = _required(details, 'sku', 80)
            resolution = workflow['resolutions'].get(sku) or {}
            if resolution.get('state') != 'awaiting_customer':
                raise OrderOperationConflict('there is no open replacement proposal to withdraw')
            workflow['resolutions'][sku] = {'state': 'withdrawn'}
            workflow['state'] = 'supplier_followup'
        elif action == 'record_customer_choice':
            self._record_customer_choice(workflow, order, plan, details, note, today)
        elif action == 'confirm_additional_payment':
            sku = _required(details, 'sku', 80)
            reference = _required(details, 'transaction_reference', 120)
            amount = _positive(details.get('amount_toman'))
            resolution = workflow['resolutions'].get(sku) or {}
            expected = max(0, int(resolution.get('approved_line_total_toman', 0)) - _line_total(order, sku))
            if resolution.get('settlement_status') != 'additional_payment_required' or amount != expected:
                raise OrderOperationConflict('additional payment does not match the accepted replacement difference')
            resolution.update({'settlement_status': 'confirmed', 'transaction_reference': reference,
                               'settled_at': str(today)})
            workflow['resolutions'][sku] = resolution
        elif action == 'confirm_refund':
            refund_id = _required(details, 'refund_id', 64)
            reference = _required(details, 'transaction_reference', 120)
            amount = _positive(details.get('amount_toman'))
            refund = next((item for item in workflow['refunds'] if item['refund_id'] == refund_id), None)
            if not refund or refund['status'] != 'requested' or amount != refund['amount_toman']:
                raise OrderOperationConflict('refund receipt does not match an open refund request')
            refund.update({'status': 'confirmed', 'transaction_reference': reference,
                           'confirmed_at': str(today)})
            if workflow['state'] == 'cancelled_refund_pending' and all(
                    item['status'] == 'confirmed' for item in workflow['refunds']):
                workflow['state'] = 'cancelled'
        elif action == 'mark_packed':
            workflow['packing'] = {'status': 'packed', 'packed_at': str(today), 'note': str(note).strip()[:1000]}
            workflow['state'] = 'packed'
        elif action == 'mark_dispatched':
            if workflow['packing'].get('status') != 'packed':
                raise OrderOperationConflict('pack the complete agreed order before dispatch')
            workflow['dispatch'] = {'status': 'dispatched', 'tracking_reference':
                _required(details, 'tracking_reference', 120), 'carrier': _text(details.get('carrier'), 120),
                'handed_over_at': _text(details.get('handed_over_at'), 40) or str(today)}
            workflow['state'] = 'dispatched'
        elif action == 'mark_delivered':
            if workflow['dispatch'].get('status') != 'dispatched':
                raise OrderOperationConflict('record dispatch before delivery')
            workflow['delivery'] = {'status': 'delivered', 'delivered_at':
                _text(details.get('delivered_at'), 40) or str(today)}
            workflow['state'] = 'delivered'
        elif action == 'open_aftercare':
            workflow['aftercare'] = {'status': 'open', 'issue': _required(details, 'issue', 500),
                'next_action': _text(details.get('next_action'), 300)}
            workflow['state'] = 'aftercare'
        elif action == 'resolve_aftercare':
            if workflow['aftercare'].get('status') != 'open':
                raise OrderOperationConflict('there is no open aftercare issue')
            workflow['aftercare'].update({'status': 'resolved', 'resolved_at': str(today),
                                          'resolution': _text(details.get('resolution'), 500)})
            workflow['state'] = 'delivered'
        elif action == 'close':
            if workflow['delivery'].get('status') != 'delivered':
                raise OrderOperationConflict('order must be recorded delivered before closure')
            if workflow['aftercare'].get('status') == 'open':
                raise OrderOperationConflict('resolve aftercare before closing the order')
            if any(item['status'] == 'requested' for item in workflow['refunds']):
                raise OrderOperationConflict('confirm all actual refunds before closing the order')
            workflow['state'] = 'closed'
            workflow['closed_at'] = str(today)
        else:
            raise ValueError('unsupported order workflow action')
        workflow['last_event'] = {'action': action, 'actor_id': actor_id,
                                  'note': str(note).strip()[:1000], 'at': str(today)}

    def _record_customer_choice(self, workflow, order, plan, details, note, today):
        sku = _required(details, 'sku', 80)
        choice = details.get('choice')
        if choice not in {'replacement', 'partial_refund', 'full_cancellation'}:
            raise ValueError('unsupported customer choice')
        if choice == 'replacement':
            resolution = workflow['resolutions'].get(sku) or {}
            proposal = resolution.get('proposal') or {}
            if resolution.get('state') != 'awaiting_customer':
                raise OrderOperationConflict('offer a replacement before recording customer acceptance')
            if details.get('replacement_sku') != proposal.get('replacement_sku'):
                raise OrderOperationConflict('customer choice does not match the offered replacement')
            _ensure_customer_choice_after_supplier_status(workflow, sku)
            approved_total = _nonnegative(details.get('approved_line_total_toman'))
            if approved_total != proposal['proposed_line_total_toman']:
                raise OrderOperationConflict('accepted amount must match the displayed replacement proposal')
            original_total = _line_total(order, sku)
            difference = approved_total - original_total
            resolution.update({'state': 'replacement_accepted', 'choice': choice,
                'replacement_sku': proposal['replacement_sku'], 'replacement_units': proposal['replacement_units'],
                'approved_line_total_toman': approved_total,
                'settlement_status': 'equal' if difference == 0 else ('additional_payment_required' if difference > 0 else 'refund_requested'),
                'customer_difference_approved': details.get('customer_difference_approved') is True})
            if difference > 0 and not resolution['customer_difference_approved']:
                raise OrderOperationConflict('customer approval is required for a higher replacement total')
            workflow['resolutions'][sku] = resolution
            if difference < 0:
                _assert_refund_capacity(workflow, order, abs(difference))
                self._request_refund(workflow, order['id'], sku, abs(difference), 'replacement_price_difference', today)
            workflow['state'] = 'customer_decision_recorded'
        elif choice == 'partial_refund':
            amount = _positive(details.get('refund_amount_toman'))
            _ensure_customer_choice_after_supplier_status(workflow, sku)
            if _shortfall(plan, sku) <= 0:
                raise OrderOperationConflict('there is no missing quantity on this line to refund')
            if amount > _line_total(order, sku):
                raise OrderOperationConflict('partial refund cannot exceed the paid line total')
            if (workflow.get('resolutions', {}).get(sku) or {}).get('state') in {
                    'partial_refund','replacement_accepted','full_cancellation'}:
                raise OrderOperationConflict('a customer resolution is already recorded for this line')
            _assert_refund_capacity(workflow, order, amount)
            workflow['resolutions'][sku] = {'state': 'partial_refund', 'choice': choice,
                'refund_amount_toman': amount}
            self._request_refund(workflow, order['id'], sku, amount, 'shortage', today)
            workflow['state'] = 'refund_pending'
        else:
            amount = _positive(details.get('refund_amount_toman'))
            _validate_full_cancellation_refund(workflow, order, details)
            for line in plan.get('lines', []):
                workflow['resolutions'][line['sku']] = {'state': 'full_cancellation', 'choice': choice}
            self._request_refund(workflow, order['id'], '', amount, 'full_cancellation', today)
            workflow['state'] = 'cancelled_refund_pending'

    def _request_refund(self, workflow, order_id, sku, amount, reason, today):
        refund_id = hashlib.sha256(f'{order_id}|{sku}|{reason}|{amount}'.encode()).hexdigest()[:32]
        if not any(item['refund_id'] == refund_id for item in workflow['refunds']):
            workflow['refunds'].append({'refund_id': refund_id, 'sku': sku, 'reason': reason,
                'amount_toman': amount, 'status': 'requested', 'requested_at': str(today)})

    def _reserve_replacement(self, order, plan, workflow, details, inventory, today):
        sku = _required(details, 'sku', 80)
        resolution = workflow.get('resolutions', {}).get(sku) or {}
        proposal = resolution.get('proposal') or {}
        if resolution.get('state') != 'awaiting_customer' or details.get('replacement_sku') != proposal.get('replacement_sku'):
            raise OrderOperationConflict('replacement is not the one offered for this line')
        _ensure_customer_choice_after_supplier_status(workflow, sku)
        candidate = proposal['replacement_sku']
        existing_skus = {line.get('sku') for line in order.get('lines', [])}
        selected_skus = {item.get('replacement_sku') for item in workflow.get('resolutions', {}).values()}
        if candidate in existing_skus or candidate in selected_skus:
            raise OrderOperationConflict('replacement SKU is already used in this order')
        allocations = (inventory or InventoryLots(self.path)).reserve(order_id=order['id'], sku=candidate,
            units=proposal['replacement_units'], today=today)
        reserved = sum(item['units'] for item in allocations if item['state'] == 'reserved')
        if reserved != proposal['replacement_units']:
            (inventory or InventoryLots(self.path)).change_reservation(order_id=order['id'], sku=candidate,
                from_state='reserved', to_state='released')
            raise OrderOperationConflict('replacement stock is no longer available')

    def _validate_replacement_choice(self, order, workflow, details):
        sku = _required(details, 'sku', 80)
        resolution = workflow.get('resolutions', {}).get(sku) or {}
        proposal = resolution.get('proposal') or {}
        if resolution.get('state') != 'awaiting_customer' or details.get('replacement_sku') != proposal.get('replacement_sku'):
            raise OrderOperationConflict('replacement is not the one offered for this line')
        approved_total = _nonnegative(details.get('approved_line_total_toman'))
        if approved_total != proposal.get('proposed_line_total_toman'):
            raise OrderOperationConflict('accepted amount must match the displayed replacement proposal')
        difference = approved_total - _line_total(order, sku)
        if difference > 0 and details.get('customer_difference_approved') is not True:
            raise OrderOperationConflict('customer approval is required for a higher replacement total')
        if difference < 0:
            _assert_refund_capacity(workflow, order, abs(difference))

    def _receive_supplier_units(self, order, plan, workflow, details, inventory, today):
        sku = _required(details, 'sku', 80)
        lot_id = _required(details, 'lot_id', 120)
        accepted = _positive(details.get('accepted_units'))
        procurement = workflow.get('procurement', {}).get(sku) or {}
        if procurement.get('state') not in {'ordered', 'partially_received', 'unavailable'}:
            raise OrderOperationConflict('record a supplier order before its receipt')
        remaining = int(procurement.get('expected_supplier_units') or 0) - int(procurement.get('received_units') or 0)
        if accepted > remaining:
            raise OrderOperationConflict('accepted quantity exceeds the open supplier order quantity')
        lot = (inventory or InventoryLots(self.path)).get_lot(lot_id)
        if not lot or lot['sku'] != sku or lot['units'] < accepted:
            raise OrderOperationConflict('receipt lot does not match the supplier line and accepted quantity')
        if procurement.get('purchase_date') and lot.get('purchase_date') != procurement.get('purchase_date'):
            raise OrderOperationConflict('receipt purchase date does not match the recorded supplier purchase')
        today_lots = (inventory or InventoryLots(self.path)).list_lots(today=today)
        eligible = next((row for row in today_lots if row['lot_id'] == lot_id), None)
        if not eligible or eligible['available_units'] < accepted:
            raise OrderOperationConflict('received lot is expired, held, future-dated or already reserved')
        resolution_state=(workflow.get('resolutions', {}).get(sku) or {}).get('state')
        if workflow['state'] in {'cancelled_refund_pending', 'cancelled'} or resolution_state in {
                'partial_refund','replacement_accepted','full_cancellation'}:
            return
        lines = [line for line in order.get('lines', []) if line['sku'] == sku]
        demand = sum(line['qty'] for line in lines)
        allocations = (inventory or InventoryLots(self.path)).reserve(order_id=order['id'], sku=sku,
            units=demand, today=today)
        if not allocations and accepted:
            raise OrderOperationConflict('received lot is not eligible for reservation; check expiry and receipt date')

    def _assert_packable(self, plan, workflow):
        if workflow['state'] in {'cancelled_refund_pending', 'cancelled', 'closed'}:
            raise OrderOperationConflict('cancelled or closed order cannot be packed')
        for line in plan.get('lines', []):
            if line.get('shortfall_units', 0) <= 0:
                continue
            resolution = workflow.get('resolutions', {}).get(line['sku']) or {}
            if resolution.get('state') not in {'replacement_accepted', 'partial_refund'}:
                raise OrderOperationConflict('resolve every supplier shortfall before packing')
        if any(refund['status'] == 'requested' for refund in workflow.get('refunds', [])):
            raise OrderOperationConflict('confirm any required refund before packing')
        if any(item.get('settlement_status') == 'additional_payment_required'
               for item in workflow.get('resolutions', {}).values()):
            raise OrderOperationConflict('confirm any approved replacement price difference before packing')

    def _fulfill_reservations(self, order_id):
        with self._connect() as db:
            db.execute("UPDATE stock_allocations SET state='fulfilled' WHERE order_id=? AND state='reserved'",
                       (order_id,))


def _refresh_plan(path, order_id, today):
    from catalog.order_fulfillment import PaidOrderPlanner
    planner = PaidOrderPlanner(path)
    source = planner.source_order(order_id)
    if not source:
        raise OrderOperationConflict('paid order source is unavailable')
    return planner.plan(source, today=today, _force=True)


def _procurement_line(plan, sku):
    row = next((item for item in plan.get('procurement', []) if item.get('sku') == sku), None)
    if not row:
        raise OrderOperationConflict('SKU has no open procurement proposal')
    return row


def _shortfall(plan, sku):
    row = next((item for item in plan.get('lines', []) if item.get('sku') == sku), None)
    return int((row or {}).get('shortfall_units') or 0)


def _line_total(order, sku):
    return sum(int(line.get('total') or 0) for line in order.get('lines', []) if line.get('sku') == sku)


def _ensure_customer_choice_after_supplier_status(workflow, sku):
    state=(workflow.get('procurement', {}).get(sku) or {}).get('state')
    if state in {'ordered','partially_received'}:
        raise OrderOperationConflict('record supplier unavailability before asking the customer to choose')


def _assert_refund_capacity(workflow, order, amount):
    if any(item.get('status') == 'requested' for item in workflow.get('refunds', [])):
        raise OrderOperationConflict('confirm the open refund before recording another refund choice')
    confirmed = sum(int(item.get('amount_toman') or 0) for item in workflow.get('refunds', [])
                    if item.get('status') == 'confirmed')
    if amount <= 0 or confirmed + amount > int(order.get('total') or 0):
        raise OrderOperationConflict('refund amount would exceed the original paid order total')


def _validate_full_cancellation_refund(workflow, order, details):
    if any(item.get('status') == 'requested' for item in workflow.get('refunds', [])):
        raise OrderOperationConflict('confirm the open refund before recording full cancellation')
    confirmed = sum(int(item.get('amount_toman') or 0) for item in workflow.get('refunds', [])
                    if item.get('status') == 'confirmed')
    remaining = int(order.get('total') or 0) - confirmed
    if remaining <= 0 or details.get('refund_amount_toman') != remaining:
        raise OrderOperationConflict('full cancellation refund must equal the unpaid remainder of the original total')


def _required(details, key, limit):
    value = _text(details.get(key), limit)
    if not value:
        raise ValueError(f'{key} is required')
    return value


def _positive(value):
    if isinstance(value, bool) or not isinstance(value, int) or value <= 0:
        raise ValueError('positive whole amount required')
    return value


def _nonnegative(value):
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise ValueError('non-negative whole amount required')
    return value


def _text(value, limit):
    return str(value or '').strip()[:limit]


def _encode(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, allow_nan=False)
