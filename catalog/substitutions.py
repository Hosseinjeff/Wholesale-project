"""Conservative shortlist of customer-visible alternatives from owned stock."""
from __future__ import annotations

import re
import unicodedata


_STOP = {'and', 'or', 'the', 'with', 'in', 'of', 'عدد', 'عددی', 'تایی', 'بسته', 'باکس', 'کارتن'}


def suggest_owned_substitutes(*, target_line, shortfall, products, lots, excluded_skus=(), limit=5):
    """Suggest only currently sellable catalog products with enough owned units.

    Similarity is a ranking hint, not a claim of equivalent quality. The owner and
    customer must review the candidate and its price before changing an order.
    """
    if not isinstance(target_line, dict) or isinstance(shortfall, bool) or not isinstance(shortfall, int) or shortfall <= 0:
        return []
    available = {}
    for lot in lots or []:
        sku = str(lot.get('sku') or '').strip()
        units = lot.get('available_units')
        if sku and isinstance(units, int) and units > 0:
            available[sku] = available.get(sku, 0) + units
    target_sku = str(target_line.get('sku') or '').strip()
    excluded = set(map(str, excluded_skus)) | {target_sku}
    target_tokens = _tokens(target_line.get('name'))
    target_category = _key(target_line.get('category'))
    candidates = []
    for product in products or []:
        sku = str(product.get('product_id') or '').strip()
        stock = available.get(sku, 0)
        if not sku or sku in excluded or stock < shortfall:
            continue
        tokens = _tokens(product.get('name'))
        common = sorted(target_tokens & tokens)
        category_match = bool(target_category and target_category == _key(product.get('category')))
        union = target_tokens | tokens
        score = (len(common) / len(union)) if union else 0.0
        if category_match:
            score = max(score, 0.35)
        if not common and not category_match:
            continue
        candidates.append({
            'sku': sku,
            'name': str(product.get('name') or '')[:200],
            'category': str(product.get('category') or '')[:120],
            'available_units': stock,
            'required_units': shortfall,
            'price_floor_toman': _positive_int(product.get('floor')),
            'price_ceiling_toman': _positive_int(product.get('ceiling')),
            'match_score': round(score, 3),
            'matched_terms': common,
            'review_required': True,
        })
    candidates.sort(key=lambda row: (-row['match_score'], row['price_floor_toman'] or 10**18, row['name'], row['sku']))
    return candidates[:max(1, min(int(limit), 10))]


def _tokens(value):
    text = _key(value)
    return {token for token in re.findall(r'[\w]+', text, flags=re.UNICODE)
            if len(token) > 1 and token not in _STOP}


def _key(value):
    text = unicodedata.normalize('NFKC', str(value or '')).casefold()
    return text.translate(str.maketrans({'ي':'ی','ك':'ک','ۀ':'ه','ة':'ه'}))


def _positive_int(value):
    try:
        number = int(value)
    except (TypeError, ValueError):
        return 0
    return number if number > 0 else 0
