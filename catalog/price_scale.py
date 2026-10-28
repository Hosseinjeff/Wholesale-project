"""Conservative price-role fallback recovered from GAS reference/history logic."""
from __future__ import annotations

import re
from difflib import SequenceMatcher

from reference_import.importer import HEADERS as REFERENCE_HEADERS, normalize_name
from reference_import.matching import package_count


def _name(value):
    text = normalize_name(str(value or ''))
    text = re.sub(r'\([^)]*\)', ' ', text)
    text = re.sub(r'\d+\s*(?:ع|عدد|عددی|تایی|ف)', ' ', text)
    text = re.sub(r'[\d.,/\\*×x]+', ' ', text, flags=re.I)
    return re.sub(r'\s+', ' ', text).strip()


def _similarity(left, right):
    a, b = _name(left), _name(right)
    if not a or not b:
        return 0.0
    if a == b:
        return 1.0
    if a in b or b in a:
        return .9
    at = [part for part in a.split() if len(part) > 1]
    bt = [part for part in b.split() if len(part) > 1]
    return sum(token in set(bt) for token in at) / max(len(at), len(bt), 1)


def _number(value):
    try:
        result = float(value or 0)
        return result if result > 0 else 0.0
    except (TypeError, ValueError):
        return 0.0


def _attribute_score(left, right):
    a, b = str(left or '').strip().casefold(), str(right or '').strip().casefold()
    if not a or not b:
        return 0.0
    if a == b:
        return 1.0
    return .7 if a in b or b in a else 0.0


def _distance(value, baseline):
    return abs(value - baseline) / baseline if value > 0 and baseline > 0 else 999.0


def _category_stats(product, reference_rows):
    category = normalize_name(str(product.get('category') or ''))
    if not category:
        return None
    refs = []
    for row in reference_rows or []:
        if not isinstance(row, (list, tuple)) or len(row) != len(REFERENCE_HEADERS):
            continue
        ref_category = normalize_name(str(row[REFERENCE_HEADERS.index('Category')] or ''))
        if not ref_category or (category not in ref_category and ref_category not in category):
            continue
        refs.append(row)
    if len(refs) < 3:
        return None
    unit_idx = REFERENCE_HEADERS.index('Reference Unit Price')
    package_idx = REFERENCE_HEADERS.index('Reference Package Price')
    code_idx = REFERENCE_HEADERS.index('Product Code')
    key_idx = REFERENCE_HEADERS.index('Normalized Key')
    name_idx = REFERENCE_HEADERS.index('Product Name')
    units = sorted(_number(row[unit_idx]) for row in refs if _number(row[unit_idx]))
    packages = sorted(_number(row[package_idx]) for row in refs if _number(row[package_idx]))
    median_unit = units[len(units) // 2] if units else 0
    median_package = packages[len(packages) // 2] if packages else 0
    ids, names = [], []
    for row in refs:
        identity = str(row[code_idx] or row[key_idx] or '')
        label = str(row[name_idx] or '')
        if identity and identity not in ids:
            ids.append(identity)
        if label and label not in names:
            names.append(label)
    return {
        'count': len(refs), 'unit': median_unit, 'package': median_package,
        'anchor_id': ','.join(ids),
        'anchor_name': 'Category median: ' + str(product.get('category') or '')
            + ((' [' + ' | '.join(names) + ']') if names else ''),
        'anchor_score': max((_similarity(product.get('name'), row[name_idx]) for row in refs), default=0),
    }


def _history(product, message, offer_records, limit=8):
    target_name = str(product.get('name') or product.get('product_name') or '')
    if not target_name:
        return {'count': 0, 'median': 0, 'prices': []}
    target_brand = str(product.get('brand') or '')
    target_category = str(product.get('category') or '')
    target_variant = str(product.get('variation_type') or product.get('variant') or '')
    target_count = _number(package_count(product))
    target_channel = str(message.get('channel_username') or message.get('channel') or '').strip().casefold()
    matches = []
    for offer in reversed(offer_records or []):
        if str(offer.get('Status') or '').strip().lower() != 'imported':
            continue
        if str(offer.get('Price Type') or '').strip().lower() not in {'unit', 'single', 'per_unit'}:
            continue
        price = _number(offer.get('Sale Price'))
        if not price:
            continue
        name_score = _similarity(target_name, offer.get('Product Name'))
        if name_score < .72:
            continue
        for target, key in ((target_brand, 'Brand'), (target_category, 'Category'),
                            (target_variant, 'Variation Type')):
            value = offer.get(key)
            if target and value and _attribute_score(target, value) == 0:
                break
        else:
            old_count = _number(package_count({'package_count': offer.get('Package Count'),
                                               'packaging': offer.get('Packaging')}))
            if target_count and old_count and old_count != target_count:
                continue
            old_channel = str(offer.get('Channel Username') or '').strip().casefold()
            if target_channel and old_channel and old_channel != target_channel and name_score < .9:
                continue
            matches.append((price, name_score, offer))
            if len(matches) >= limit:
                break
    prices = sorted(item[0] for item in matches)
    if not prices:
        return {'count': 0, 'median': 0, 'prices': []}
    middle = len(prices) // 2
    median = prices[middle] if len(prices) % 2 else (prices[middle - 1] + prices[middle]) / 2
    return {
        'count': len(prices), 'median': median, 'prices': prices,
        'anchor_ids': [str(row.get('Offer ID') or '') for _, _, row in matches if row.get('Offer ID')],
        'anchor_names': [str(row.get('Product Name') or '') for _, _, row in matches if row.get('Product Name')],
        'best_match_score': max((score for _, score, _ in matches), default=0),
    }


def assess_price_scale(product, message, offers, reference_rows=(), *, min_history=1):
    """Return a reviewable price-scale assessment; never act on explicit roles."""
    product = product or {}
    count = _number(package_count(product))
    currency = str(product.get('currency') or 'IRT').strip().upper()
    if currency not in {'IRT','TOMAN','T','تومان','تومن'}:
        return {'certain': False, 'action': 'currency_review',
                'reason': 'Currency must be reviewed before reference/history price comparison'}
    explicit_role = str(product.get('confirmed_price_role') or product.get('price_type') or '').strip().lower()
    if explicit_role in {'unit', 'package', 'both'}:
        return {'certain': True, 'action': 'explicit_role', 'package_count': count}
    raw = _number(product.get('detected_price') or product.get('source_price_amount')
                  or product.get('unit_price') or product.get('sale_price') or product.get('price')
                  or product.get('package_price'))
    if raw <= 0:
        return {'certain': True, 'action': 'none', 'package_count': count}

    stats = _category_stats(product, reference_rows)
    if stats:
        unit_distance = _distance(raw, stats['unit'])
        package_distance = _distance(raw, stats['package'])
        if stats['unit'] and unit_distance <= .40 and (not stats['package'] or unit_distance + .15 < package_distance):
            return {'certain': True, 'action': 'category_unit_price', 'method': 'category_median',
                'detected_price': raw, 'suggested_unit_price': int(raw + .5),
                'suggested_package_price': int(raw * count + .5) if count > 1 else 0,
                'package_count': count, 'reference_unit_price': stats['unit'],
                'reference_package_price': stats['package'], 'anchor_type': 'reference',
                'anchor_id': stats['anchor_id'], 'anchor_name': stats['anchor_name'],
                'anchor_score': stats['anchor_score'], 'category_reference_count': stats['count']}
        if stats['package'] and count > 1 and package_distance <= .40 and (
                not stats['unit'] or package_distance + .15 < unit_distance):
            return {'certain': True, 'action': 'category_package_price', 'method': 'category_median',
                'detected_price': raw, 'suggested_unit_price': int(raw / count + .5),
                'suggested_package_price': int(raw + .5), 'package_count': count,
                'reference_unit_price': stats['unit'], 'reference_package_price': stats['package'],
                'anchor_type': 'reference', 'anchor_id': stats['anchor_id'],
                'anchor_name': stats['anchor_name'], 'anchor_score': stats['anchor_score'],
                'category_reference_count': stats['count']}

    history = _history(product, message or {}, offers or [])
    if history['count'] < max(1, int(min_history or 1)) or history['median'] <= 0:
        return {'certain': count <= 1, 'action': 'confirm_price_scale' if count > 1 else 'none',
                'reason': 'Pack count is known but price role has no sufficient confirmed history',
                'detected_price': raw, 'package_count': count, 'history_count': history['count']}
    if count <= 1:
        return {'certain': True, 'action': 'none', 'package_count': count,
                'historical_median_unit_price': history['median']}
    current_ratio = _distance(raw, history['median'])
    divided = raw / count
    divided_ratio = _distance(divided, history['median'])
    likely_package = raw >= history['median'] * 2.5 and divided_ratio <= .35 and divided_ratio < current_ratio * .5
    if likely_package:
        return {'certain': True, 'action': 'history_package_price', 'method': 'history_confirmed_package',
            'detected_price': raw, 'suggested_unit_price': int(divided + .5),
            'suggested_package_price': int(raw + .5), 'package_count': count,
            'historical_median_unit_price': history['median'], 'history_count': history['count'],
            'anchor_type': 'history', 'anchor_id': ','.join(history.get('anchor_ids') or []),
            'anchor_name': ' | '.join(history.get('anchor_names') or []) or 'Confirmed supplier offer history',
            'anchor_score': history.get('best_match_score') or ''}
    if current_ratio > 1.5:
        return {'certain': False, 'action': 'confirm_price_scale',
            'reason': 'Unit price deviates strongly from recent confirmed history',
            'detected_price': raw, 'suggested_unit_price': int(raw + .5),
            'suggested_package_price': int(raw * count + .5), 'package_count': count,
            'historical_median_unit_price': history['median'], 'history_count': history['count']}
    return {'certain': True, 'action': 'history_unit_price', 'method': 'history_confirmed',
        'detected_price': raw, 'suggested_unit_price': int(raw + .5), 'package_count': count,
        'historical_median_unit_price': history['median'], 'history_count': history['count'],
        'anchor_type': 'history', 'anchor_id': ','.join(history.get('anchor_ids') or []),
        'anchor_name': ' | '.join(history.get('anchor_names') or []) or 'Confirmed supplier offer history',
        'anchor_score': history.get('best_match_score') or ''}


def apply_price_scale(product, assessment):
    """Apply only a certain role inference and attach source/anchor audit fields."""
    if not assessment:
        return product
    action = assessment.get('action')
    if action in {'category_unit_price', 'category_package_price',
                  'history_package_price', 'history_unit_price'} and assessment.get('certain'):
        unit = int(assessment.get('suggested_unit_price') or assessment.get('detected_price') or 0)
        if unit > 0:
            product['sale_price'] = unit
            product['price'] = unit
            product['price_type'] = 'unit'
            product['confirmed_price_role'] = 'unit'
            if action in {'category_package_price', 'history_package_price'}:
                product['package_price'] = int(assessment.get('suggested_package_price') or 0)
                product['min_qty'] = max(int(_number(product.get('min_qty'))),
                                         int(_number(assessment.get('package_count'))))
            product['price_scale_status'] = 'confirmed'
            reasons = [part.strip() for part in str(product.get('review_reason') or '').split(';') if part.strip()]
            unresolved = [part for part in reasons if part.lower() not in {
                'supplier price role is unclear',
                'sale price role is ambiguous: unit or package',
                'pack count is detected but sale price role is not explicit',
            }]
            if len(unresolved) != len(reasons):
                product['review_reason'] = '; '.join(unresolved)
                confidence = _number(product.get('confidence') or product.get('extraction_confidence'))
                if not unresolved and confidence >= .85:
                    product['needs_review'] = False
    elif action == 'confirm_price_scale':
        product['needs_review'] = True
        reason = str(assessment.get('reason') or 'Supplier price scale needs owner review')
        existing = [part.strip() for part in str(product.get('review_reason') or '').split(';') if part.strip()]
        if reason not in existing:
            existing.append(reason)
        product['review_reason'] = '; '.join(existing)
        product['price_scale_status'] = 'needs_review'
    product['detected_price'] = assessment.get('detected_price') or product.get('detected_price') or ''
    product['price_scale_method'] = assessment.get('method') or ''
    product['price_anchor_type'] = assessment.get('anchor_type') or ''
    product['price_anchor_id'] = assessment.get('anchor_id') or ''
    product['price_anchor_name'] = assessment.get('anchor_name') or ''
    product['price_anchor_score'] = assessment.get('anchor_score') or ''
    product['historical_median_unit_price'] = assessment.get('historical_median_unit_price') or ''
    product['price_scale_reason'] = assessment.get('reason') or ''
    product['category_reference_count'] = assessment.get('category_reference_count') or ''
    return product
