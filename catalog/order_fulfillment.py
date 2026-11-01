"""Durable paid-order stock allocation and fixed-pack purchase proposals.

This module never submits a supplier order. It reserves eligible owned lots after
Woo reports payment complete and stores a reviewable proposal for any shortfall.
"""
from __future__ import annotations

import hashlib
import json
import os
import sqlite3
from contextlib import contextmanager

from catalog.inventory_lots import InventoryLots
from catalog.orders import normalize_order
from catalog.procurement import plan_purchase


class OrderPlanConflict(ValueError):
    """The paid order's lines or amount changed after allocation began."""


class PaidOrderPlanner:
    def __init__(self, path):
        self.path = os.path.realpath(os.fspath(path))
        self.inventory = InventoryLots(self.path)
        with self._connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS paid_order_plans (
                order_id TEXT PRIMARY KEY, request_hash TEXT NOT NULL,
                terms_hash TEXT NOT NULL, source_order TEXT NOT NULL,
                result TEXT, state TEXT NOT NULL, updated_at TEXT NOT NULL,
                supplier_terms TEXT NOT NULL DEFAULT '{}');
                CREATE TABLE IF NOT EXISTS paid_order_decisions (
                decision_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT NOT NULL,
                actor_id TEXT NOT NULL, note TEXT NOT NULL, terms TEXT NOT NULL,
                created_at TEXT NOT NULL);''')
            columns = {row['name'] for row in db.execute('PRAGMA table_info(paid_order_plans)')}
            if 'supplier_terms' not in columns:
                db.execute("ALTER TABLE paid_order_plans ADD COLUMN supplier_terms TEXT NOT NULL DEFAULT '{}'")
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

    def plan(self, order, *, today, supplier_terms=None, actor_id='system', note='', _force=False):
        normalized = normalize_order(order)
        order_id = normalized['id']
        if not order_id:
            raise ValueError('order id required')
        supplied_terms = _normalize_terms(supplier_terms or {})

        woo_status = normalized['woo_status']
        if woo_status in {'cancelled', 'refunded', 'failed'} or normalized['payment_status'] == 'refunded':
            released = self.inventory.release_order(order_id=order_id)
            result = {'order_id': order_id, 'state': 'released', 'released_units': released,
                      'purchase_authorized': False}
            self._save_terminal(order_id, normalized, result, 'released', today)
            return result
        if normalized['payment_status'] != 'verified':
            return {'order_id': order_id, 'state': 'payment_pending', 'purchase_authorized': False}

        quantities = _line_quantities(normalized['lines'])
        offer_ids = _offer_ids_by_sku(normalized['lines'])
        request_data = {
            'order_id': order_id,
            'total': normalized['total'],
            'currency': 'IRT',
            'lines': [{'sku': sku, 'units': units,
                       'supplier_offer_ids': sorted(offer_ids.get(sku, set()))}
                      for sku, units in sorted(quantities.items())],
        }
        request_json = _encode(request_data)
        request_hash = hashlib.sha256(request_json.encode()).hexdigest()
        source_order = _encode(normalized)
        timestamp = str(today)

        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            prior = db.execute('SELECT * FROM paid_order_plans WHERE order_id=?', (order_id,)).fetchone()
            if prior and prior['request_hash'] != request_hash:
                raise OrderPlanConflict('paid order content changed; explicit owner reconciliation is required')
            if prior and prior['state'] == 'released':
                raise OrderPlanConflict('released order cannot reserve the same lots again')
            prior_terms = _normalize_terms(json.loads(prior['supplier_terms'] or '{}')) if prior else {}
            terms = {**prior_terms, **supplied_terms}
            if supplied_terms and (actor_id == 'system' or not str(note or '').strip()):
                raise ValueError('supplier terms require an authorized actor and note')
            if supplied_terms and prior and prior['result']:
                prior_result = json.loads(prior['result'])
                eligible = {row['sku'] for row in prior_result.get('procurement', [])
                            if row.get('state') in {'needs_supplier_terms', 'invalid_supplier_terms', 'proposed'}}
                if set(supplied_terms) - eligible:
                    raise ValueError('supplier terms may only resolve a recorded order shortfall')
            for sku, offer in terms.items():
                if not offer.get('source_offer_id') or offer['source_offer_id'] not in offer_ids.get(sku, set()):
                    raise ValueError('supplier terms must cite an offer attached to this paid order line')
            terms_json = _encode(terms)
            terms_hash = hashlib.sha256(terms_json.encode()).hexdigest()
            if (not _force and prior and prior['terms_hash'] == terms_hash
                    and prior['result'] and prior['state'] != 'planning'):
                return json.loads(prior['result'])
            db.execute('''INSERT INTO paid_order_plans
                (order_id,request_hash,terms_hash,source_order,result,state,updated_at,supplier_terms)
                VALUES (?,?,?,?,NULL,'planning',?,?)
                ON CONFLICT(order_id) DO UPDATE SET terms_hash=excluded.terms_hash,
                source_order=excluded.source_order,state='planning',updated_at=excluded.updated_at,
                supplier_terms=excluded.supplier_terms''',
                (order_id, request_hash, terms_hash, source_order, timestamp, terms_json))
            if supplied_terms and (not prior or prior['terms_hash'] != terms_hash):
                db.execute('INSERT INTO paid_order_decisions (order_id,actor_id,note,terms,created_at) VALUES (?,?,?,?,?)',
                           (order_id, str(actor_id), str(note).strip()[:1000], terms_json, timestamp))

        result = self._build_plan(order_id, quantities, terms, today, normalized['lines'])
        result['terms_hash'] = terms_hash
        with self._connect() as db:
            db.execute('UPDATE paid_order_plans SET result=?,state=?,updated_at=? WHERE order_id=? AND request_hash=?',
                       (_encode(result), result['state'], timestamp, order_id, request_hash))
        return result

    def _build_plan(self, order_id, quantities, supplier_terms, today, source_lines):
        lines = []
        procurement = []
        has_shortage = False
        needs_review = False
        for sku, demand in sorted(quantities.items()):
            allocations = self.inventory.reserve(order_id=order_id, sku=sku, units=demand, today=today)
            reserved = sum(row['units'] for row in allocations if row['state'] == 'reserved')
            shortfall = max(0, demand - reserved)
            line = {'sku': sku, 'customer_units': demand, 'owned_units_reserved': reserved,
                    'shortfall_units': shortfall, 'lots': allocations}
            lines.append(line)
            if not shortfall:
                continue
            has_shortage = True
            offer = supplier_terms.get(sku)
            if offer is None:
                needs_review = True
                procurement.append({'sku': sku, 'state': 'needs_supplier_terms',
                                    'shortfall_units': shortfall, 'purchase_authorized': False})
                continue
            try:
                proposal = plan_purchase(
                    [{'order_id': order_id, 'sku': sku, 'units': shortfall}],
                    [{**offer, 'sku': sku}], {sku: 0})[0]
            except (TypeError, ValueError):
                needs_review = True
                procurement.append({'sku': sku, 'state': 'invalid_supplier_terms',
                                    'shortfall_units': shortfall, 'purchase_authorized': False})
                continue
            procurement.append({**proposal, 'state': 'proposed', 'purchase_authorized': False,
                                'source_offer_id': str(offer.get('source_offer_id') or '')})

        if needs_review:
            state = 'needs_owner_review'
        elif has_shortage:
            state = 'procurement_proposed'
        else:
            state = 'reserved'
        source_summary = [{'sku': line['sku'], 'supplier_offer_id': line['supplier_offer_id']}
                          for line in source_lines if line.get('sku')]
        return {'order_id': order_id, 'state': state, 'lines': lines,
                'source_lines': source_summary, 'procurement': procurement,
                'purchase_authorized': False}

    def _save_terminal(self, order_id, normalized, result, state, today):
        request_json = _encode({'order_id': order_id, 'terminal_state': state,
                                'payment_status': normalized['payment_status']})
        request_hash = hashlib.sha256(request_json.encode()).hexdigest()
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            prior = db.execute('SELECT state FROM paid_order_plans WHERE order_id=?', (order_id,)).fetchone()
            if prior and prior['state'] == 'released':
                return
            db.execute('''INSERT INTO paid_order_plans
                (order_id,request_hash,terms_hash,source_order,result,state,updated_at)
                VALUES (?,?,?, ?,?,?,?)
                ON CONFLICT(order_id) DO UPDATE SET result=excluded.result,
                state=excluded.state,updated_at=excluded.updated_at''',
                (order_id, request_hash, '', _encode(normalized), _encode(result), state, str(today)))

    def get(self, order_id):
        with self._connect() as db:
            row = db.execute('SELECT result FROM paid_order_plans WHERE order_id=?', (str(order_id),)).fetchone()
        return json.loads(row['result']) if row and row['result'] else None

    def source_order(self, order_id):
        with self._connect() as db:
            row = db.execute('SELECT source_order FROM paid_order_plans WHERE order_id=?',
                             (str(order_id),)).fetchone()
        return json.loads(row['source_order']) if row and row['source_order'] else None

    def list_plans(self):
        with self._connect() as db:
            rows = db.execute("SELECT result FROM paid_order_plans WHERE result IS NOT NULL "
                              "AND state IN ('needs_owner_review','procurement_proposed','reserved','released') "
                              "ORDER BY updated_at,order_id").fetchall()
        return [json.loads(row['result']) for row in rows]


def _line_quantities(lines):
    quantities = {}
    if not lines:
        raise ValueError('paid order has no valid lines')
    for line in lines:
        sku = str(line.get('sku') or '').strip()
        units = line.get('qty')
        if not sku or isinstance(units, bool) or not isinstance(units, int) or units <= 0:
            raise ValueError('each paid order line needs a stable sku and positive whole quantity')
        quantities[sku] = quantities.get(sku, 0) + units
    return quantities


def _offer_ids_by_sku(lines):
    result = {}
    for line in lines:
        sku = str(line.get('sku') or '').strip()
        offer_id = str(line.get('supplier_offer_id') or '').strip()
        if sku and offer_id:
            result.setdefault(sku, set()).add(offer_id)
    return result


def _normalize_terms(terms):
    if not isinstance(terms, dict):
        raise ValueError('supplier terms must be mapped by sku')
    normalized = {}
    for sku, offer in terms.items():
        if not isinstance(sku, str) or not sku.strip() or not isinstance(offer, dict):
            raise ValueError('invalid supplier terms')
        normalized[sku.strip()] = {key: value for key, value in offer.items()
                                   if key in {'supplier', 'units_per_pack', 'pack_price_toman',
                                              'minimum_packs', 'pack_multiple', 'source_offer_id'}}
    return normalized


def _encode(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, allow_nan=False)
