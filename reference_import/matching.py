"""Conservative reference candidates and price-role hints for item review.

No supplier value is overwritten. Callers must first enforce reference freshness and
preserve the snapshot/confirmation workflow; this module does not activate data.
"""
from difflib import SequenceMatcher
from math import isfinite
import hashlib
import json
import re
from catalog.measurements import normalize_measurement_tokens
from .importer import normalize_name, HEADERS


def _text(value):
    return normalize_name(str(value or ''))


def _name(value):
    """Normalize explicit size tokens before punctuation is removed for matching."""
    return _text(normalize_measurement_tokens(str(value or '')))


def _number(value):
    if isinstance(value,bool):return 0
    try:
        result=float(value or 0)
        return result if isfinite(result) and result > 0 else 0
    except (TypeError,ValueError):return 0


def package_count(product):
    value = _number(product.get('package_count'))
    if value:
        return value
    packaging = str(product.get('packaging') or product.get('name') or '')
    match = re.search(r'(?<!\d)(\d{1,4})\s*(?:عددی|تایی|عدد|ف|تکه|قوطی|بسته)', packaging)
    return float(match.group(1)) if match else 0


def product_signature(product):
    """Stable identity for a human-approved mapping, scoped to its snapshot."""
    identity = [
        _name(product.get('clean_name') or product.get('name')),
        _text(product.get('brand')),
        _text(product.get('category')),
        _text(product.get('variant') or product.get('variation_type')),
        str(package_count(product) or ''),
        _text(product.get('volume')),
        _text(product.get('weight')),
    ]
    return hashlib.sha256(json.dumps(identity, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()


def _reference(row):
    return {'reference_id': str(row[16] or row[2]), 'name': str(row[0]),
            'unit_price': _number(row[4]), 'package_price': _number(row[3]),
            'package_count': _number(row[6])}


def ranked_candidates(product, rows, *, limit=10):
    """Rank suggestions for the owner. A score never approves a match by itself."""
    weights = {'name': .45, 'brand': .14, 'category': .12, 'variant': .10,
               'package_count': .07, 'volume': .06, 'weight': .06}
    columns = {'name': 1, 'brand': 12, 'category': 13, 'variant': 11,
               'package_count': 6, 'volume': 8, 'weight': 9}
    incoming = {
        'name': _name(product.get('clean_name') or product.get('name')),
        'brand': _text(product.get('brand')),
        'category': _text(product.get('category')),
        'variant': _text(product.get('variant') or product.get('variation_type')),
        'package_count': str(package_count(product) or ''),
        'volume': _text(product.get('volume')),
        'weight': _text(product.get('weight')),
    }
    if not incoming['name']:
        return []
    scored = []
    for row in rows:
        if not isinstance(row, (list, tuple)) or len(row) != len(HEADERS):
            continue
        components = {}
        weighted, denominator = 0.0, 0.0
        for key, weight in weights.items():
            left = incoming[key]
            right = str(row[columns[key]] or '') if columns[key] < len(row) else ''
            if key == 'package_count':
                right = str(_number(right) or '')
            else:
                right = _name(right) if key == 'name' else _text(right)
            if not left:
                continue
            denominator += weight
            score = SequenceMatcher(None, left, right).ratio() if right else 0.0
            components[key] = round(score, 4)
            weighted += weight * score
        score = weighted / denominator if denominator else 0.0
        if score < .35:
            continue
        candidate = _reference(row)
        if not candidate['reference_id']:
            continue
        candidate.update({'score': round(score, 4), 'component_scores': components})
        scored.append(candidate)
    scored.sort(key=lambda item: (-item['score'], item['reference_id']))
    return scored[:max(1, min(int(limit), 20))]


def candidate_match(product, rows, *, overrides=None):
    """Exact normalized identity plus strict explicit-attribute agreement.

    Unspecified attributes cannot confirm an explicitly specified one. Multiple
    candidates and conflicting attributes require operator review, not selection.
    """
    name=_name(product.get('clean_name') or product.get('name'))
    if not name:return {'state':'needs_review','reason':'missing_name','candidates':[]}
    candidates=[]
    for row in rows:
        if not isinstance(row,(list,tuple)) or len(row)!=len(HEADERS):
            return {'state':'needs_review','reason':'invalid_reference_rows','candidates':[]}
        if _name(row[1] or row[0]) != name:continue
        compatible=True
        for key,index in (('brand',12),('variant',11),('volume',8),('weight',9)):
            incoming=_text(product.get(key)); reference=_text(row[index])
            if key in {'volume','weight'}:
                # The message parser historically places explicit gram/kilo
                # markers in its `volume` field; compare either measurement
                # against both source columns while keeping labels separate.
                alternatives={reference,_text(row[8]),_text(row[9])}-{''}
                if incoming and incoming not in alternatives:compatible=False;break
            elif incoming and (not reference or incoming != reference):compatible=False;break
        incoming_count=_number(product.get('package_count'))
        reference_count=_number(row[6])
        if incoming_count and (not reference_count or incoming_count!=reference_count):compatible=False
        if compatible:
            candidates.append(_reference(row))
    pinned = (overrides or {}).get(product_signature(product))
    if isinstance(pinned, dict):
        selected = next((candidate for candidate in (_reference(row) for row in rows
            if isinstance(row, (list, tuple)) and len(row) == len(HEADERS))
            if candidate['reference_id'] == str(pinned.get('reference_id') or '')), None)
        if selected:
            selected['owner_price_role'] = str(pinned.get('price_role') or '')
            selected['owner_decision_id'] = str(pinned.get('decision_id') or '')
            return {'state':'candidate','reason':'owner_approved_mapping','candidates':[selected]}
    if not candidates:return {'state':'needs_review','reason':'no_compatible_reference','candidates':[]}
    if len(candidates)>1:return {'state':'needs_review','reason':'multiple_candidates','candidates':candidates}
    if not candidates[0]['reference_id']:
        return {'state':'needs_review','reason':'missing_reference_identity','candidates':candidates}
    return {'state':'candidate','reason':'exact_compatible_identity','candidates':candidates}


def price_role_hint(advertised_price, reference):
    """Offer an auditable unit/carton hint using recovered 35%/.15 heuristics.

    The original supplier amount remains unchanged. This is not an instruction to
    apply the role automatically or a confidence-calibrated probability.
    """
    price=_number(advertised_price)
    unit=_number(reference.get('unit_price'))
    package=_number(reference.get('package_price'))
    if not price or not unit or not package or unit==package:
        return {'role':'needs_review','reason':'insufficient_reference'}
    unit_distance=abs(price-unit)/unit
    package_distance=abs(price-package)/package
    if min(unit_distance,package_distance)>0.35 or abs(unit_distance-package_distance)<0.15:
        return {'role':'needs_review','reason':'ambiguous_or_distant'}
    return {'role':'unit' if unit_distance < package_distance else 'package',
            'reason':'reference_scale_hint','supplier_price':price,
            'unit_distance':unit_distance,'package_distance':package_distance}
