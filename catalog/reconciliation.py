"""Read-only receipt-to-store reconciliation for the owner dashboard."""
from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import statistics
from typing import Any
from urllib.parse import urlparse

import requests

from catalog.sync import _records


def _text(value: Any) -> str:
    return str(value or '').strip()


def _time(value: Any) -> str:
    try:
        return datetime.fromtimestamp(float(value), timezone.utc).isoformat()
    except (TypeError, ValueError, OSError):
        return ''


def _positive_number(value: Any) -> int:
    try:
        number = int(float(value))
        return number if number > 0 else 0
    except (TypeError, ValueError, OverflowError):
        return 0


def _read_rows(store, sheet: str) -> list[dict[str, Any]]:
    rows = store.read(sheet)
    return _records(rows)[1]


def _woo_catalog(site_url: str) -> dict[str, Any]:
    parsed = urlparse(str(site_url or '').strip())
    if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
            or parsed.path not in {'', '/'} or parsed.query or parsed.fragment):
        return {'state': 'not_configured', 'products': {}, 'checked_at': ''}
    endpoint = f'{parsed.scheme}://{parsed.netloc}/wp-json/wc/store/v1/products'
    found: dict[str, dict[str, Any]] = {}
    page = 1
    pages = 1
    try:
        while page <= pages and page <= 10:
            response = requests.get(endpoint, params={'per_page': 100, 'page': page},
                                    timeout=(3, 6), allow_redirects=False,
                                    headers={'Accept': 'application/json'})
            if response.status_code != 200:
                return {'state': 'unavailable', 'products': {}, 'checked_at': ''}
            payload = response.json()
            if not isinstance(payload, list):
                return {'state': 'unavailable', 'products': {}, 'checked_at': ''}
            for item in payload:
                if not isinstance(item, dict):
                    continue
                sku = _text(item.get('sku'))
                if not sku:
                    continue
                found[sku] = {
                    'purchasable': bool(item.get('is_purchasable')),
                    'in_stock': bool(item.get('is_in_stock')),
                }
            try:
                pages = max(1, int(response.headers.get('X-WP-TotalPages') or '1'))
            except ValueError:
                pages = 1
            page += 1
        return {
            'state': 'truncated' if pages > 10 else 'checked',
            'products': found,
            'checked_at': datetime.now(timezone.utc).isoformat(),
        }
    except (requests.RequestException, ValueError):
        return {'state': 'unavailable', 'products': {}, 'checked_at': ''}


def _matching_offers(message: dict[str, Any], offers: list[dict[str, Any]]) -> list[dict[str, Any]]:
    message_id = _text(message.get('message_id'))
    channel = _text(message.get('channel'))
    timestamp = _text(message.get('source_timestamp'))
    batch_id = _text(message.get('batch_id'))
    source_text = _text(message.get('_source_text'))
    matches = [offer for offer in offers
               if _text(offer.get('Message ID')) == message_id
               and _text(offer.get('Channel Username')) == channel
               and (not timestamp or _text(offer.get('Message Timestamp')) == timestamp)
               and (not source_text or _text(offer.get('Original Message')) == source_text)]
    if not matches and batch_id:
        matches = [offer for offer in offers
                   if _text(offer.get('Batch ID')) == batch_id
                   and _text(offer.get('Message ID')) == message_id
                   and _text(offer.get('Channel Username')) == channel]
    raw_ids = {_text(offer.get('Offer ID')) for offer in matches if _text(offer.get('Offer ID'))}
    derived = [offer for offer in offers if _text(offer.get('Derived From Offer ID')) in raw_ids]
    known = {_text(offer.get('Offer ID')) for offer in matches}
    return matches + [offer for offer in derived if _text(offer.get('Offer ID')) not in known]


def build_reconciliation_report(store, queue, *, site_url: str, limit: int = 200) -> dict[str, Any]:
    """Join private queue receipts to Sheets and public Woo visibility without writes."""
    receipt_data = queue.reconciliation_receipts(limit=limit)
    messages = _read_rows(store, 'MessageData')
    offers = _read_rows(store, 'Offers')
    products = _read_rows(store, 'Products')
    message_index: dict[tuple[str, str, str], list[dict[str, Any]]] = {}
    for row in messages:
        key = (_text(row.get('ID')), _text(row.get('Channel Username')), _text(row.get('Batch ID')))
        message_index.setdefault(key, []).append(row)
    product_by_id: dict[str, list[dict[str, Any]]] = {}
    for row in products:
        product_id = _text(row.get('Product ID'))
        if product_id:
            product_by_id.setdefault(product_id, []).append(row)

    expected_by_source = {}
    for row in products:
        source_id = _text(row.get('Product ID'))
        name = _text(row.get('Product Name'))
        channel = _text(row.get('Channel Username'))
        if not source_id or (not name and _text(row.get('Status')).lower() not in {'needs_review','not_product','skipped'}):
            continue
        sku = hashlib.sha256(f'{channel}|{name}'.encode()).hexdigest()[:12]
        expected_by_source[source_id] = {
            'id': sku,
            'status': _text(row.get('Status')).lower(),
            'supplier_availability': _text(row.get('Stock Status')).lower(),
        }
    woo = _woo_catalog(site_url)
    receipt_rows = []
    totals = {
        'receipts': len(receipt_data['receipts']), 'message_data': 0,
        'offers': 0, 'offers_needing_review': 0, 'products': 0,
        'publication_done': 0, 'publication_pending': 0,
        'woo_missing': 0, 'woo_blocked': 0,
    }
    latency_samples = []
    for receipt in receipt_data['receipts']:
        batch_id = _text(receipt.get('batch_id'))
        key = (_text(receipt.get('message_id')), _text(receipt.get('channel')), batch_id)
        matched_messages = message_index.get(key, [])
        message = matched_messages[-1] if matched_messages else {}
        joined = {
            **receipt,
            '_source_text': _text(message.get('Content')),
        }
        matched_offers = _matching_offers(joined, offers)
        offer_ids = {_text(row.get('Offer ID')) for row in matched_offers if _text(row.get('Offer ID'))}
        product_ids = {_text(row.get('Product ID')) for row in matched_offers if _text(row.get('Product ID'))}
        current_products = [row for product_id in product_ids for row in product_by_id.get(product_id, [])]
        owner_decisions = [row for row in matched_offers if _text(row.get('Owner Decision ID'))]
        current_offer_ids = {_text(row.get('Latest Offer ID')) for row in current_products}
        current_products = [row for row in current_products
                            if not current_offer_ids or _text(row.get('Latest Offer ID')) in offer_ids]
        offers_by_id = {_text(row.get('Offer ID')): row for row in matched_offers if _text(row.get('Offer ID'))}
        product_details = []
        for product in current_products:
            product_id = _text(product.get('Product ID'))
            latest_offer = offers_by_id.get(_text(product.get('Latest Offer ID')), {})
            channel = _text(product.get('Channel Username'))
            name = _text(product.get('Product Name'))
            product_details.append({
                'product_id': product_id,
                'name': name,
                'status': _text(product.get('Status')),
                'supplier_price': _text(product.get('Sale Price')),
                'customer_price': _text(product.get('Consumer Price') or product.get('Actual Price')),
                'price_basis': _text(latest_offer.get('Price Type') or product.get('Price Type')),
                'package_price': _text(latest_offer.get('Package Price')),
                'package_count': _text(product.get('Package Count') or latest_offer.get('Package Count')),
                'reference_match_id': _text(product.get('Reference Match ID') or latest_offer.get('Reference Match ID')),
                'reference_approval_state': _text(product.get('Reference Approval State')),
                'reference_price_change_percent': _text(product.get('Reference Price Change Percent')),
                'source_post_url': _text(product.get('Source Post URL') or latest_offer.get('Source Post URL')),
                'woo_sku': hashlib.sha256(f'{channel}|{name}'.encode()).hexdigest()[:12],
            })
        latest_review_offers = ([row for row in matched_offers if _text(row.get('Offer ID')) in current_offer_ids]
                                if current_offer_ids else matched_offers)
        open_reviews = [row for row in latest_review_offers
                        if _text(row.get('Status')).lower() == 'needs_review']

        woo_states = []
        woo_states_by_product = {}
        for product_id in product_ids:
            expected = expected_by_source.get(product_id)
            if not expected:
                woo_states_by_product[product_id] = 'no_catalog_projection'
                continue
            published = woo['products'].get(_text(expected.get('id')))
            expected_status = _text(expected.get('status'))
            if expected_status in {'needs_review', 'not_product', 'skipped'}:
                if published and published['purchasable']:
                    state = 'held_but_purchasable'
                    totals['woo_blocked'] += 1
                elif published or woo['state'] == 'checked':
                    state = 'held'
                else:
                    state = ('not_checked_catalog_truncated' if woo['state'] == 'truncated'
                             else 'not_checked')
            elif not published:
                state = ('not_checked_catalog_truncated' if woo['state'] == 'truncated'
                         else 'not_checked' if woo['state'] != 'checked' else 'not_visible')
                if state == 'not_visible':
                    totals['woo_missing'] += 1
            elif not published['purchasable']:
                stock = _text(expected.get('supplier_availability')).lower()
                state = ('supplier_out_of_stock' if stock in {'out of stock', 'outofstock', 'ناموجود'}
                         else 'not_purchasable')
                totals['woo_blocked'] += 1
            else:
                state = 'purchasable'
            woo_states_by_product[product_id] = state
            woo_states.append(state)
        for product in product_details:
            product['woo_state'] = woo_states_by_product.get(product['product_id'], 'not_checked')

        ai_status = _text(message.get('AI Status')) or 'not_in_sheets'
        publication_candidates = []
        if _text(receipt.get('publication_state')) != 'not_queued':
            publication_candidates.append({
                'state': _text(receipt.get('publication_state')),
                'attempts': int(receipt.get('publication_attempts') or 0),
                'error': _text(receipt.get('publication_error')),
                'created_at': receipt.get('publication_created_at'),
                'updated_at': receipt.get('publication_updated_at'),
                'pushed': bool(receipt.get('publication_pushed')),
                'count': int(receipt.get('publication_count') or 0),
            })
        decisions = receipt_data.get('publication_by_decision') or {}
        for offer in owner_decisions:
            publication = decisions.get(_text(offer.get('Owner Decision ID')))
            if publication:
                publication_candidates.append(publication)
        publication_candidates.sort(key=lambda item: float(item.get('created_at') or 0))
        latest_publication = publication_candidates[-1] if publication_candidates else {}
        publication_state = _text(latest_publication.get('state')) or 'not_queued'
        if publication_state == 'done':
            totals['publication_done'] += 1
        elif publication_state not in {'not_queued', ''}:
            totals['publication_pending'] += 1
        totals['message_data'] += int(bool(message))
        totals['offers'] += len(matched_offers)
        totals['offers_needing_review'] += len(open_reviews)
        totals['products'] += len(current_products)

        imported_at = _text(message.get('Import Timestamp'))
        if receipt.get('received_at') and imported_at:
            try:
                imported = datetime.fromisoformat(imported_at.replace('Z', '+00:00')).timestamp()
                latency_samples.append(max(0.0, imported - float(receipt['received_at'])))
            except (TypeError, ValueError, OSError):
                pass

        receipt_rows.append({
            'message_id': _text(receipt.get('message_id')),
            'channel': _text(receipt.get('channel')),
            'source_timestamp': _text(receipt.get('source_timestamp')),
            'received_at': _time(receipt.get('received_at')),
            'batch_state': _text(receipt.get('batch_state')),
            'batch_attempts': int(receipt.get('batch_attempts') or 0),
            'batch_error': _text(receipt.get('batch_error')),
            'extraction_state': ai_status,
            'offers': len(matched_offers),
            'offers_needing_review': len(open_reviews),
            'owner_decisions': len(owner_decisions),
            'product_count': len(current_products),
            'product_statuses': sorted({_text(row.get('Status')) for row in current_products if _text(row.get('Status'))}),
            'product_details': product_details,
            'price_order_checks': sum(
                1 for row in current_products
                if _positive_number(row.get('Sale Price'))
                and _positive_number(row.get('Consumer Price') or row.get('Actual Price'))
                    > _positive_number(row.get('Sale Price'))
            ),
            'publication_state': publication_state,
            'publication_attempts': int(latest_publication.get('attempts') or 0),
            'publication_error': _text(latest_publication.get('error')),
            'publication_pushed': bool(latest_publication.get('pushed')),
            'woo_states': woo_states,
            'source_row_found': bool(message),
        })

    validation = {
        'state': 'partial' if not products or woo['state'] in {'unavailable', 'not_configured', 'truncated'} else 'checked',
        'woo_state': woo['state'],
        'woo_checked_at': woo['checked_at'],
        'woo_product_count': len(woo['products']),
        'expected_product_count': len(expected_by_source),
        'receipt_rows_shown': len(receipt_rows),
        'total_receipts': int(receipt_data['total_receipts']),
        'receipt_rows_truncated': receipt_data['truncated'],
        'intake_to_sheet_latency_samples': len(latency_samples),
        'intake_to_sheet_median_seconds': round(statistics.median(latency_samples), 1) if latency_samples else None,
        'ai_accuracy': 'not_measured',
        'ai_accuracy_note': 'AI field accuracy needs an owner-labelled message set; this report shows operational outcomes only.',
    }
    return {
        'ok': True,
        'generated_at': datetime.now(timezone.utc).isoformat(),
        'summary': totals,
        'validation': validation,
        'receipts': receipt_rows,
    }
