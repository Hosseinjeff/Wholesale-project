"""Read-only receipt-to-store reconciliation for the owner dashboard."""
from __future__ import annotations

from datetime import datetime, timezone
import os
import re
import statistics
from collections import Counter, defaultdict
from typing import Any
from urllib.parse import urlparse

import requests

from catalog.source_integrity import product_offer_link_issues
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
            'identity_records': _woo_identity_records(site_url),
            'checked_at': datetime.now(timezone.utc).isoformat(),
        }
    except (requests.RequestException, ValueError):
        return {'state': 'unavailable', 'products': {}, 'checked_at': ''}


def _woo_identity_records(site_url: str) -> list[dict[str, Any]] | None:
    """Read private Woo IDs so preserved legacy SKUs do not look like missing products."""
    secret = os.getenv('SHOP_NOTIFY_SECRET') or ''
    parsed = urlparse(str(site_url or '').strip())
    if (not secret or parsed.scheme != 'https' or not parsed.hostname
            or parsed.username or parsed.password or parsed.path not in {'', '/'}
            or parsed.query or parsed.fragment):
        return None
    try:
        response = requests.get(
            f'{parsed.scheme}://{parsed.netloc}/',
            params={'molavi-sync': '1', 'mode': 'catalog_identity'},
            timeout=(3, 8), allow_redirects=False,
            headers={'Accept': 'application/json', 'X-Shop-Token': secret,
                     'User-Agent': 'MolaviCatalogAudit/1'},
        )
        if response.status_code != 200:
            return None
        payload = response.json()
        records = payload.get('products') if isinstance(payload, dict) and payload.get('ok') is True else None
        if not isinstance(records, list) or len(records) > 2000:
            return None
        return [item for item in records if isinstance(item, dict)]
    except (requests.RequestException, ValueError):
        return None


def _woo_identity_match(expected: dict[str, Any], woo: dict[str, Any]) -> tuple[dict[str, Any] | None, str]:
    """Match through the durable source ID first, then safe unique identities."""
    records = woo.get('identity_records')
    if not isinstance(records, list):
        return None, ''
    checks = []
    source_record_id = _text(expected.get('source_record_id'))
    expected_sku = _text(expected.get('id'))
    if source_record_id:
        checks.append(('source_record_id', lambda row: _text(row.get('source_record_id')) == source_record_id))
    if expected_sku:
        checks.append(('source_sku', lambda row: _text(row.get('source_id')) == expected_sku))
        checks.append(('sku', lambda row: _text(row.get('sku')) == expected_sku))
    supplier = _text(expected.get('supplier')).casefold()
    name = re.sub(r'\s+', ' ', _text(expected.get('display_name') or expected.get('product_name'))).casefold()
    if supplier and name and not expected.get('supplier_name_ambiguous'):
        checks.append(('supplier_name', lambda row:
            _text(row.get('supplier')).casefold() == supplier
            and re.sub(r'\s+', ' ', _text(row.get('name'))).casefold() == name))
    for method, predicate in checks:
        matches = [row for row in records if predicate(row)]
        if len(matches) == 1:
            return matches[0], method
        if len(matches) > 1:
            return None, 'ambiguous'
    return None, ''


def _woo_state_for(expected: dict[str, Any] | None, woo: dict[str, Any]) -> str:
    if not expected or expected.get('status') == 'no_projection':
        return 'no_catalog_projection'
    identity, _matched_by = _woo_identity_match(expected, woo)
    if identity is not None:
        published = bool(identity.get('published'))
        purchasable = bool(identity.get('purchasable'))
        expected_status = _text(expected.get('status'))
        if expected_status in {'hold', 'needs_review', 'not_product', 'skipped'}:
            return 'held_but_purchasable' if published and purchasable else 'held'
        if not published:
            return 'not_visible'
        if not purchasable:
            return 'not_purchasable'
        if _text(identity.get('sku')) != _text(expected.get('id')):
            return 'sku_mismatch'
        return 'purchasable'
    published = woo['products'].get(_text(expected.get('id')))
    expected_status = _text(expected.get('status'))
    if expected_status in {'hold', 'needs_review', 'not_product', 'skipped'}:
        if published and published['purchasable']:
            return 'held_but_purchasable'
        if published or woo['state'] == 'checked':
            return 'held'
        return ('not_checked_catalog_truncated' if woo['state'] == 'truncated'
                else 'not_checked')
    if not published:
        if woo['state'] == 'checked':
            return 'not_visible'
        return ('not_checked_catalog_truncated' if woo['state'] == 'truncated'
                else 'not_checked')
    if not published['purchasable']:
        stock = _text(expected.get('supplier_availability')).lower()
        return ('supplier_out_of_stock' if stock in {'out of stock', 'outofstock', 'ناموجود'}
                else 'not_purchasable')
    return 'purchasable'


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
    source_integrity_issues = product_offer_link_issues(products,offers)
    message_index: dict[tuple[str, str, str], list[dict[str, Any]]] = {}
    for row in messages:
        key = (_text(row.get('ID')), _text(row.get('Channel Username')), _text(row.get('Batch ID')))
        message_index.setdefault(key, []).append(row)
    product_by_id: dict[str, list[dict[str, Any]]] = {}
    product_by_latest_offer: dict[str, list[dict[str, Any]]] = {}
    for row in products:
        product_id = _text(row.get('Product ID'))
        if product_id:
            product_by_id.setdefault(product_id, []).append(row)
        latest_offer_id = _text(row.get('Latest Offer ID'))
        if latest_offer_id:
            product_by_latest_offer.setdefault(latest_offer_id, []).append(row)

    try:
        # Reuse the publisher's exact hold rules so rows with missing prices,
        # stale supplier evidence, or explicit stock holds are not misreported
        # as missing from WooCommerce.
        from catalog.publish import site_products
        from catalog.product_identity import woo_sku
        projection_rows = site_products(store)
    except Exception:
        projection_rows = None
    projection_by_sku = {
        _text(item.get('id')): item for item in projection_rows or []
        if isinstance(item, dict) and _text(item.get('id'))
    }
    projection_available = projection_rows is not None
    source_issue_by_row = {
        int(issue.get('row_number') or 0) - 2: issue
        for issue in source_integrity_issues
    }
    expected_by_source = {}
    expected_keys_by_product_id = defaultdict(list)
    for row_number, row in enumerate(products, start=2):
        source_id = _text(row.get('Product ID'))
        name = _text(row.get('Product Name'))
        channel = _text(row.get('Channel Username'))
        if not source_id or (not name and _text(row.get('Status')).lower() not in {'needs_review','not_product','skipped'}):
            continue
        source_key = source_id if source_id not in expected_by_source else f'{source_id}#row-{row_number}'
        sku = woo_sku(row)
        projected = projection_by_sku.get(sku)
        expected_by_source[source_key] = {
            'product_id': source_id,
            'product_name': name,
            'display_name': _text(projected.get('name')) if projected else name,
            'supplier': _text(projected.get('supplier')) if projected else '',
            'source_record_id': source_id,
            'channel': channel,
            'id': sku,
            'status': _text(projected.get('status')) if projected else ('hold' if projection_available else 'no_projection'),
            'supplier_availability': _text(projected.get('supplier_availability')) if projected else '',
            'latest_offer_id': _text(row.get('Latest Offer ID')),
            'source_post_url': _text(row.get('Source Post URL')),
            'source_integrity_codes': source_issue_by_row.get(row_number - 2, {}).get('codes', []),
        }
        expected_keys_by_product_id[source_id].append(source_key)
    supplier_name_counts = Counter(
        (_text(expected.get('supplier')).casefold(),
         re.sub(r'\s+', ' ', _text(expected.get('display_name'))).casefold())
        for expected in expected_by_source.values()
        if _text(expected.get('supplier')) and _text(expected.get('display_name'))
    )
    for expected in expected_by_source.values():
        identity = (_text(expected.get('supplier')).casefold(),
                    re.sub(r'\s+', ' ', _text(expected.get('display_name'))).casefold())
        expected['supplier_name_ambiguous'] = supplier_name_counts[identity] > 1
    woo = _woo_catalog(site_url)
    catalog_woo_states = {
        product_id: _woo_state_for(expected, woo)
        for product_id, expected in expected_by_source.items()
    }
    for product_keys in expected_keys_by_product_id.values():
        if len(product_keys) > 1:
            for product_key in product_keys:
                catalog_woo_states[product_key] = 'ambiguous_product_id'
    catalog_counts = {
        'products_checked': len(expected_by_source),
        'woo_state': woo['state'],
        'purchasable': 0,
        'held': 0,
        'not_visible': 0,
        'sku_mismatch': 0,
        'not_purchasable': 0,
        'supplier_out_of_stock': 0,
        'held_but_purchasable': 0,
        'no_catalog_projection': 0,
        'not_checked': 0,
        'not_checked_catalog_truncated': 0,
        'ambiguous_product_id': 0,
    }
    for state in catalog_woo_states.values():
        if state in catalog_counts:
            catalog_counts[state] += 1
        if state == 'sku_mismatch':
            # The product is live, but Woo retains a legacy SKU used by older orders.
            catalog_counts['purchasable'] += 1
    detail_states = {
        'not_visible', 'sku_mismatch', 'held_but_purchasable', 'ambiguous_product_id',
        'no_catalog_projection', 'not_checked', 'not_checked_catalog_truncated',
    }
    catalog_issue_details = []
    for source_key, expected in expected_by_source.items():
        state = catalog_woo_states[source_key]
        if state not in detail_states:
            continue
        identity, match_method = _woo_identity_match(expected, woo)
        catalog_issue_details.append({
            'product_id': expected['product_id'],
            'product_name': expected['product_name'],
            'channel': expected['channel'],
            'woo_sku': expected['id'],
            'actual_woo_sku': _text(identity.get('sku')) if identity else '',
            'woo_match_method': match_method,
            'state': state,
            'product_status': expected['status'],
            'latest_offer_id': expected['latest_offer_id'],
            'source_post_url': expected['source_post_url'],
            'source_integrity_codes': expected['source_integrity_codes'],
        })
    product_states = [{
        'product_id': expected['product_id'],
        'product_name': expected['product_name'],
        'channel': expected['channel'],
        'woo_sku': expected['id'],
        'woo_state': catalog_woo_states[source_key],
        'product_status': expected['status'],
        'latest_offer_id': expected['latest_offer_id'],
        'source_post_url': expected['source_post_url'],
        'source_integrity_codes': expected['source_integrity_codes'],
    } for source_key, expected in expected_by_source.items()]
    catalog_counts['issues'] = catalog_issue_details[:limit]
    receipt_rows = []
    totals = {
        'receipts': len(receipt_data['receipts']), 'message_data': 0,
        'offers': 0, 'offers_needing_review': 0, 'products': 0,
        'publication_done': 0, 'publication_pending': 0,
        'woo_missing': catalog_counts['not_visible'],
        'woo_blocked': (catalog_counts['not_purchasable']
                        + catalog_counts['supplier_out_of_stock']
                        + catalog_counts['held_but_purchasable']),
        'products_with_source_integrity_issues': len(source_integrity_issues),
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
        legacy_product_ids = {_text(row.get('Product ID')) for row in matched_offers
                              if _text(row.get('Product ID'))}
        current_products_by_id = {}
        for product_id in legacy_product_ids:
            for product in product_by_id.get(product_id, []):
                latest_offer_id = _text(product.get('Latest Offer ID'))
                if not latest_offer_id or latest_offer_id in offer_ids:
                    current_products_by_id[_text(product.get('Product ID'))] = product
        # The operational Offers schema is append-only and has no Product ID
        # column. Products points back to its current offer through Latest Offer ID.
        # Join on that authoritative link so receipts still show their current
        # product state, including owner-confirmed derived offers.
        for offer_id in offer_ids:
            for product in product_by_latest_offer.get(offer_id, []):
                current_products_by_id[_text(product.get('Product ID'))] = product
        current_products = list(current_products_by_id.values())
        product_ids = {_text(row.get('Product ID')) for row in current_products
                        if _text(row.get('Product ID'))}
        owner_decisions = [row for row in matched_offers if _text(row.get('Owner Decision ID'))]
        current_offer_ids = {_text(row.get('Latest Offer ID')) for row in current_products
                             if _text(row.get('Latest Offer ID'))}
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
                'woo_sku': woo_sku(product),
                'latest_offer_id': _text(product.get('Latest Offer ID')),
            })
        latest_review_offers = ([row for row in matched_offers if _text(row.get('Offer ID')) in current_offer_ids]
                                if current_offer_ids else matched_offers)
        open_reviews = [row for row in latest_review_offers
                        if _text(row.get('Status')).lower() == 'needs_review']

        woo_states = []
        woo_states_by_product = {}
        for product_id in product_ids:
            product_keys = expected_keys_by_product_id.get(product_id, [])
            state = (catalog_woo_states.get(product_keys[0], 'no_catalog_projection')
                     if len(product_keys) == 1 else
                     'ambiguous_product_id' if len(product_keys) > 1 else
                     'no_catalog_projection')
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
        'state': 'partial' if (not products or not projection_available
            or woo['state'] in {'unavailable', 'not_configured', 'truncated'}) else 'checked',
        'catalog_projection': 'checked' if projection_available else 'unavailable',
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
        'catalog_woo': catalog_counts,
        'product_states': product_states[:limit],
        'product_states_truncated': len(product_states) > limit,
        'source_integrity': {
            'products_checked': len(products),
            'products_with_issues': len(source_integrity_issues),
            'issues': source_integrity_issues[:limit],
            'issues_truncated': len(source_integrity_issues) > limit,
        },
        'receipts': receipt_rows,
    }
