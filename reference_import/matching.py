"""Conservative reference candidates and price-role hints for item review.

No supplier value is overwritten. Callers must first enforce reference freshness and
preserve the snapshot/confirmation workflow; this module does not activate data.
"""
from difflib import SequenceMatcher
from math import isfinite
import hashlib
import json
import re
from catalog.measurements import measurement_signature, normalize_measurement_tokens
from .importer import normalize_name, HEADERS

try:
    from rapidfuzz.fuzz import ratio as _rapid_ratio
except ImportError:  # Keep the matcher usable in minimal desktop/test installs.
    _rapid_ratio = None


def _similarity(left, right):
    """Return the existing normalized sequence ratio using the fast native scorer."""
    if _rapid_ratio is not None:
        return _rapid_ratio(left, right) / 100.0
    return SequenceMatcher(None, left, right).ratio()


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


# --- Full-identity tokenisation (catalog-extraction iteration 1, port of
# molavi-domain-reference-matching.php). Rezvan titles are compact coded
# strings (glued compounds like هزارجزیره470گ, (Nف) price codes, Nع pack
# codes, ***, (قدیم), (R150)). The identity layer de-glues them, strips
# the codes out of the compared name, and compares token sets order-free,
# so brand + product + variant + size + pack + price can agree even when
# the LCS name string caps at ~0.73-0.79. The string name_similarity
# path is untouched: identity is a parallel path, never a replacement.

BRAND_ALIASES = {'تمیز': 'تیمز'}


def alias_brand(brand):
    """Known extraction typos mapped to their brand (owner-visible allowlist).

    The matcher never fuzzy-matches a brand: a misspelling matches only
    when it is listed here. 'تمیز' is Gemini's misreading of 'تیمز'.
    """
    return BRAND_ALIASES.get(_text(brand), _text(brand))


def identity_deglue(text):
    text = re.sub(r'(?<=[^\W\d_])(?=\d)', ' ', str(text or ''))
    text = re.sub(r'(?<=\d)(?=[^\W\d_])', ' ', text)
    return text


_IDENTITY_UNITS = {'گرم', 'گرمی', 'گ', 'کیلوگرم', 'کیلویی', 'کیلو', 'میلی‌گرم',
                   'میلیگرم', 'میل', 'مل', 'لیتر', 'لیتری', 'ل', 'سی',
                   'cc', 'ml', 'mg', 'kg', 'g'}
_IDENTITY_PACK_WORDS = {'عددی', 'عدد', 'ع', 'تایی', 'تا', 'بسته', 'کارتن',
                        'باکس', 'قوطی', 'تکه'}
_IDENTITY_VARIANT_WORDS = {'طعم', 'مزه', 'رنگ', 'مدل', 'نوع'}
_IDENTITY_STOP = {'و', 'با', 'در', 'از', 'موجود', 'مختلف', 'متنوع', 'قدیم'}


def identity_parse_title(text):
    """De-glued content tokens of a title, with sizes, pack counts, price
    codes, flavour-count phrases and connector words removed. Returns
    {'tokens': [...], 'stale': bool}; tokens are UNSPLIT compounds —
    splitting against a lexicon happens at comparison time."""
    source = str(text or '')
    stale = 'قدیم' in source
    # Sachet/format codes `(1*3)` / bare `1*3` are identity: they
    # distinguish (1*2) from (1*3) rows. Extract them BEFORE de-gluing
    # and normalisation destroy the `*`, and keep them as `1x3` tokens.
    formats = []
    for match in re.finditer(r'\(\s*(\d+)\s*[*×xX]\s*(\d+)\s*\)', source):
        formats.append(match.group(1) + 'x' + match.group(2))
    source = re.sub(r'\(\s*\d+\s*[*×xX]\s*\d+\s*\)', ' ', source)
    for match in re.finditer(r'(?<!\d)(\d+)\s*[*×]\s*(\d+)(?!\d)', source):
        formats.append(match.group(1) + 'x' + match.group(2))
    source = re.sub(r'(?<!\d)\d+\s*[*×]\s*\d+(?!\d)', ' ', source)
    # Bare `N M` single-digit pairs are the same sachet format with the
    # `*` lost in extraction (`کافی میکس 1 3 تیمز` came from `(1*3)`).
    # Without them B2 (1*3) is indistinguishable from هات چاکلت.
    for match in re.finditer(r'(?<!\d)([1-9]) ([1-9])(?!\d)', source):
        formats.append(match.group(1) + 'x' + match.group(2))
    source = re.sub(r'(?<!\d)[1-9] [1-9](?!\d)', ' ', source)
    tokens = _text(identity_deglue(source)).split()
    out = []
    for token in tokens:
        if token in ('ف', 'f', 'r') or re.fullmatch(r'r\d+', token):
            continue
        if (token in _IDENTITY_STOP or token in _IDENTITY_UNITS
                or token in _IDENTITY_PACK_WORDS or token in _IDENTITY_VARIANT_WORDS):
            continue
        # A bare number is a size, a pack count, or a price code — never
        # product identity. Its following unit/pack word (if any) is
        # dropped by its own rule above.
        if re.fullmatch(r'\d+(?:[/.٫]\d+)?', token):
            continue
        if token not in out:
            out.append(token)
    for fmt in formats:
        if fmt not in out:
            out.append(fmt)
    return {'tokens': [BRAND_ALIASES.get(token, token) for token in out],
            'stale': stale}


def identity_split_token(token, lexicon):
    """Split a compound token against a lexicon of standalone tokens.
    A token splits only when every part is a known standalone token,
    parts >= 2 chars."""
    if len(token) < 4:
        return [token]
    for i in range(2, len(token) - 1):
        a, b = token[:i], token[i:]
        if a in lexicon and b in lexicon:
            return identity_split_token(a, lexicon) + identity_split_token(b, lexicon)
    return [token]


def identity_split_tokens(tokens, lexicon):
    out = []
    for token in tokens:
        for part in identity_split_token(token, lexicon):
            if part not in out:
                out.append(part)
    return out


def identity_brand_in_tokens(brand, tokens):
    """True when every word of the brand is a token of the (split) catalog
    title — or, for words >= 4 chars, a substring of a token — so a brand
    need not be space-delimited to be found. A brand word immediately
    preceded by طرح/سبک/شبیه is a lookalike mention, not the brand."""
    words = _text(brand).split()
    if not words:
        return False
    usable = [token for i, token in enumerate(tokens)
              if i == 0 or tokens[i - 1] not in ('طرح', 'سبک', 'شبیه')]
    for word in words:
        if not any(token == word or (len(word) >= 4 and word in token)
                   for token in usable):
            return False
    return True


def identity_words_in_tokens(text, tokens):
    """True when every word of the text occurs in the tokens."""
    words = _text(text).split()
    return bool(words) and all(word in tokens for word in words)


def strip_compared_name(text):
    """Strip pack wording and generic flavour-count phrases from a compared
    name (D §5.2): the supplier name keeps them while the catalog clean
    title strips them, so leaving them in punishes true matches and
    rewards junk rows that share only generic words (۴ طعم موجود
    ۲۴ عددی). Size tokens stay: they are identity, not pack wording."""
    text = re.sub(r'\d+\s*(?:طعم|مزه|رنگ|مدل|نوع)(?:\s*(?:موجود|مختلف|متنوع))?',
                  ' ', str(text or ''))
    text = re.sub(r'\d+\s*(?:عددی|عدد|ع|تایی|تا|بسته|کارتن|باکس|قوطی|تکه)',
                  ' ', text)
    text = re.sub(r'(?:کارتن|باکس|بسته)\s*\d+', ' ', text)
    return re.sub(r'\s+', ' ', text).strip()


_GENERIC_VARIANT = re.compile(
    r'(?:در )?\d+ (?:طعم|مزه|رنگ|مدل|نوع)(?: (?:موجود|مختلف|متنوع))?')


def identity_agreement(incoming, reference, catalog_tokens, stale, lexicon,
                       require_brand, supplier_raw_name=None):
    """Full-identity agreement between an incoming product and one catalog
    reference, given the catalog row's SPLIT content tokens and its stale
    flag. require_brand=True (backfill tier 0): the supplier brand must be
    present AND positively agreed. require_brand=False (extraction
    junction): a missing supplier brand is neutral, but a filled supplier
    brand still needs the catalog brand to agree or be inferable from the
    title. Size/pack: agreement when both sides provide the fact; missing
    on one side is unknown, never a mismatch. Category is NEVER compared.
    Tokens: containment is measured on the SUPPLIER side, strictly above
    0.80. Brand words are judged once, by brand_state, and excluded from
    token containment. Variant-determined / EXACT (iteration 2, item (b)):
    an identity match is exact when the two sides' product-token sets are
    EQUAL — neither side asserts identity the other lacks. A supplier item
    that states no variant matches a variant row only INEXACTLY: the
    catalog asserts a variant the supplier never stated, so price
    agreement alone must never auto-assign it. Exactness gates
    auto-assignment; inexact matches still surface as owner candidates.
    The variant column alone cannot decide this: Rezvan leaves it empty
    and the variant lives in the title tokens, so the rule is keyed on
    the token sets."""
    supplier_tokens = identity_split_tokens(list(dict.fromkeys(
        identity_parse_title(supplier_raw_name or str(incoming.get('name') or ''))['tokens']
        + identity_parse_title(str(incoming.get('variant') or ''))['tokens']
        + identity_parse_title(str(incoming.get('brand') or ''))['tokens'])), lexicon)
    brand = alias_brand(incoming.get('brand') or '')
    catalog_brand = alias_brand(reference.get('brand') or '')
    if not brand:
        brand_agree, brand_state = not require_brand, 'supplier_empty'
    elif catalog_brand:
        brand_agree = catalog_brand == brand
        brand_state = 'agree' if brand_agree else 'conflict'
    elif identity_brand_in_tokens(brand, catalog_tokens):
        brand_agree, brand_state = True, 'inferred_from_title'
    else:
        brand_agree, brand_state = False, 'catalog_unknown'
    brand_words = identity_split_tokens(list(dict.fromkeys(
        identity_parse_title(brand)['tokens']
        + identity_parse_title(catalog_brand)['tokens'])), lexicon)
    supplier_product = [t for t in supplier_tokens if t not in brand_words]
    catalog_product = [t for t in catalog_tokens if t not in brand_words]
    # A name that is only the brand plus size/pack words (بلک ولف 1 لیتر)
    # has no product tokens left after the removal — the brand words ARE
    # the product identity there, so fall back to the un-removed sets.
    if supplier_product:
        supplier_tokens, catalog_tokens = supplier_product, catalog_product
    common = len(set(supplier_tokens) & set(catalog_tokens))
    overlap = common / len(supplier_tokens) if supplier_tokens else 0.0
    token_agree = bool(supplier_tokens) and overlap > 0.80
    variant = _text(incoming.get('variant') or '')
    if _GENERIC_VARIANT.fullmatch(variant):
        variant = ''
    catalog_variant = _text(reference.get('variant') or '')
    if not variant:
        variant_agree = True
    elif catalog_variant:
        variant_agree = variant == catalog_variant
    else:
        variant_agree = identity_words_in_tokens(variant, catalog_tokens)
    size_agree = True
    for kind in ('volume', 'weight'):
        left = set(measurement_signature(str(incoming.get(kind) or ''), kind))
        right = set(measurement_signature(
            str(reference.get('volume') or '') + ' ' + str(reference.get('weight') or ''), kind))
        if left and right and left != right:
            size_agree = False
    supplier_pack = _number(incoming.get('package_count'))
    catalog_pack = _number(reference.get('package_count'))
    pack_agree = not supplier_pack or not catalog_pack or supplier_pack == catalog_pack
    identity = brand_agree and token_agree and variant_agree and size_agree and pack_agree
    # Exactness (iteration 2, item (b)): the effective product-token sets
    # (after the brand-word removal / name-only-brand fallback above) are
    # equal as sets. Size and pack are not in the tokens — they are already
    # required separately — and the variant dimension is inside the tokens,
    # so set equality is exactly "the variant dimension is determined".
    exact = bool(identity) and set(supplier_tokens) == set(catalog_tokens)
    if not brand_agree:
        reason = 'brand_' + brand_state
    elif not token_agree:
        reason = 'token_overlap_low'
    elif not variant_agree:
        reason = 'variant_conflict'
    elif not size_agree:
        reason = 'size_conflict'
    elif not pack_agree:
        reason = 'pack_conflict'
    else:
        reason = 'full_identity'
    return {'identity_match': identity, 'identity_exact': exact, 'brand_agree': brand_agree,
            'brand_state': brand_state, 'token_overlap': round(overlap, 4),
            'token_agree': token_agree, 'variant_agree': variant_agree,
            'size_agree': size_agree, 'pack_agree': pack_agree,
            'stale_packaging': stale, 'reason': reason}


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
            'clean_name': str(row[1] or row[0]),
            'unit_price': _number(row[4]), 'package_price': _number(row[3]),
            'consumer_price': _number(row[5]), 'package_count': _number(row[6]),
            'unit_label': str(row[7] or ''), 'volume': str(row[8] or ''),
            'weight': str(row[9] or ''), 'variant': str(row[11] or ''),
            'brand': str(row[12] or ''), 'category': str(row[13] or '')}


def _attribute_comparisons(incoming, candidate, components, source_values):
    labels = {'name': 'نام', 'brand': 'برند', 'category': 'دسته',
              'variant': 'طعم/مدل', 'package_count': 'تعداد در بسته',
              'volume': 'حجم', 'weight': 'وزن'}
    result = []
    for key, label in labels.items():
        source = str(source_values.get(key) or '').strip()
        normalized_source = str(incoming.get(key) or '').strip()
        reference = str(candidate.get(key) or '').strip()
        if not normalized_source:
            state = 'not_provided'
        elif not reference:
            state = 'reference_missing'
        elif key == 'name':
            similarity = float(components.get(key) or 0)
            state = 'match' if similarity >= .92 else ('close' if similarity >= .72 else 'conflict')
        elif key == 'package_count':
            state = 'match' if _number(normalized_source) == _number(reference) else 'conflict'
        elif key in {'volume', 'weight'}:
            state = 'match' if normalized_source == _name(reference) else 'conflict'
        else:
            state = 'match' if normalized_source == _text(reference) else 'conflict'
        result.append({'key': key, 'label': label, 'source': source,
                       'reference': reference, 'state': state})
    return result


class RankedCandidateIndex:
    """Prepare a reference snapshot once, then cheaply rank many incoming products."""

    weights = {'name': .45, 'brand': .14, 'category': .12, 'variant': .10,
               'package_count': .07, 'volume': .06, 'weight': .06}

    def __init__(self, rows):
        self.rows = []
        lexicon = {}
        staged = []
        for row in rows:
            if not isinstance(row, (list, tuple)) or len(row) != len(HEADERS):
                continue
            candidate = _reference(row)
            if not candidate['reference_id']:
                continue
            title = _text(re.sub(r'[（(][^）)]*[）)]', ' ', candidate['name']))
            values = {
                'name': strip_compared_name(_name(row[1] or row[0])),
                'brand': _text(row[12]),
                'category': _text(row[13]),
                'variant': _text(row[11]),
                'package_count': str(_number(row[6]) or ''),
                'volume': _name(row[8]),
                'weight': _name(row[9]),
            }
            # Pass 1: unsplit content tokens (variant-col words included)
            # feed the snapshot lexicon; splitting happens at comparison
            # time so the other side's tokens can supply compound parts.
            identity = identity_parse_title(candidate['name'])
            for token in identity_parse_title(str(candidate['variant'] or ''))['tokens']:
                if token not in identity['tokens']:
                    identity['tokens'].append(token)
            for token in identity['tokens']:
                lexicon[token] = True
            staged.append((candidate, values, f' {title} ', identity))
        self.lexicon = lexicon
        self.rows = staged

    def ranked_candidates(self, product, *, limit=10):
        """Rank suggestions for the owner. A score never approves a match by itself."""
        weights = self.weights
        incoming = {
            'name': strip_compared_name(_name(product.get('clean_name') or product.get('name'))),
            'brand': _text(product.get('brand')),
            'category': _text(product.get('category')),
            'variant': _text(product.get('variant') or product.get('variation_type')),
            'package_count': str(package_count(product) or ''),
            'volume': _name(product.get('volume')),
            'weight': _name(product.get('weight')),
        }
        source_values = {
            'name': str(product.get('clean_name') or product.get('name') or '').strip(),
            'brand': str(product.get('brand') or '').strip(),
            'category': str(product.get('category') or '').strip(),
            'variant': str(product.get('variant') or product.get('variation_type') or '').strip(),
            'package_count': str(package_count(product) or ''),
            'volume': str(product.get('volume') or '').strip(),
            'weight': str(product.get('weight') or '').strip(),
        }
        if not incoming['name']:
            return []
        if _GENERIC_VARIANT.fullmatch(incoming['variant']):
            incoming['variant'] = ''
        # Known extraction typos are aliased to their brand before any
        # comparison (تمیز → تیمز); an unlisted misspelling never matches.
        incoming['brand'] = alias_brand(incoming['brand'])
        raw_name = str(product.get('clean_name') or product.get('name') or '')
        # Lexicon for compound-splitting: the snapshot's standalone tokens
        # plus the incoming product's own tokens.
        lexicon = dict(self.lexicon)
        for token in identity_parse_title(raw_name)['tokens']:
            lexicon[token] = True
        scored = []
        identity_matches = []
        brand = incoming['brand']
        brand_in_supplier_title = bool(brand) and f' {brand} ' in f" {incoming['name']} "
        for reference, right_values, catalog_title, identity in self.rows:
            catalog_tokens = identity_split_tokens(identity['tokens'], lexicon)
            if brand_in_supplier_title and not right_values['brand']:
                if identity_brand_in_tokens(brand, catalog_tokens):
                    right_values = {**right_values, 'brand': brand}
                    reference = {**reference, 'brand': source_values['brand']}
            same_brand = bool(brand and brand == alias_brand(right_values['brand']))
            variant = incoming['variant']
            if same_brand and variant and not right_values['variant'] and identity_words_in_tokens(variant, catalog_tokens):
                right_values = {**right_values, 'variant': variant}
                reference = {**reference, 'variant': source_values['variant']}
            components = {}
            weighted, denominator = 0.0, 0.0
            for key, weight in weights.items():
                left = incoming[key]
                if not left:
                    continue
                right = right_values[key]
                denominator += weight
                score = _similarity(left, right) if right else 0.0
                components[key] = round(score, 4)
                weighted += weight * score
            score = weighted / denominator if denominator else 0.0
            # When the supplier brand is known, a row that is not that brand
            # needs strong name evidence to be shown at all: a طرح/سبک
            # mention or a matching pack count alone must not surface a
            # different-brand row. When the brand is unknown the old 0.35
            # floor stays, so the string tiers keep their path.
            floor = 0.5 if (not same_brand and brand) else 0.35
            if not same_brand and (score < floor or components.get('name', 0) < floor):
                continue
            candidate = {**reference}
            candidate.update({
                'score': round(score, 4), 'component_scores': components,
                'name_similarity': round(components.get('name', 0), 4),
                'comparisons': _attribute_comparisons(incoming, candidate, components, source_values),
            })
            supplier_price = _number(
                product.get('supplier_price') or product.get('detected_price')
                or product.get('sale_price') or product.get('price'))
            if supplier_price:
                candidate['supplier_price'] = supplier_price
                candidate['price_role_hint'] = price_role_hint(supplier_price, candidate)
            agreement = identity_agreement(incoming, reference, catalog_tokens,
                                           bool(identity['stale']), lexicon, True, raw_name)
            candidate['identity_match'] = agreement['identity_match']
            candidate['identity'] = {'token_overlap': agreement['token_overlap'],
                                     'brand_state': agreement['brand_state'],
                                     'reason': agreement['reason'],
                                     'identity_exact': agreement['identity_exact']}
            candidate['stale_packaging'] = agreement['stale_packaging']
            (identity_matches if same_brand else scored).append(candidate)
        # The same-brand gate is a ranking bonus now, not a filter:
        # full-identity matches first, then same-brand, then score.
        ranked = identity_matches + scored
        same_ids = {str(c['reference_id']) for c in identity_matches}
        count = (_number(incoming['package_count']) if identity_matches and brand_in_supplier_title and not any(
            incoming[key] for key in ('variant', 'volume', 'weight')) else 0)
        ranked.sort(key=lambda item: (
            not item['identity_match'],
            str(item['reference_id']) not in same_ids,
            not (count and item['package_count'] == count),
            -item['score'], item['reference_id']))
        return ranked[:max(1, min(int(limit), 20))]


def ranked_candidates(product, rows, *, limit=10):
    """Rank suggestions for one product, preserving the shared index API."""
    return RankedCandidateIndex(rows).ranked_candidates(product, limit=limit)


def explicit_reference_conflicts(product, reference):
    """Compare stated identity facts in code; missing facts never establish conflict.

    Brand/variant spelling disagreements require review, rather than an automatic
    alias assumption. Measurements compare exact base units, including the older
    parser's use of the volume column for weights.
    """
    conflicts = []
    for key in ('brand', 'variant'):
        left = product.get(key) or (product.get('variation_type') if key == 'variant' else '')
        right = reference.get(key)
        if left and right and _text(left) != _text(right):
            conflicts.append(key)
    left_count = _number(product.get('package_count'))
    right_count = _number(reference.get('package_count'))
    if left_count and right_count and left_count != right_count:
        conflicts.append('package_count')
    left_text = ' '.join(str(product.get(key) or '') for key in ('volume', 'weight'))
    right_text = ' '.join(str(reference.get(key) or '') for key in ('volume', 'weight'))
    for kind in ('volume', 'weight'):
        left = set(measurement_signature(left_text, kind))
        right = set(measurement_signature(right_text, kind))
        if left and right and left != right:
            conflicts.append(kind)
    return conflicts


def candidate_match(product, rows, *, overrides=None, compatibility_check=False):
    """Full-identity equality (iteration 1) instead of byte-identical names.

    A size-token difference alone no longer blocks it; brand/variant/size/
    pack still agree by the same rules as backfill tier 0, except a missing
    supplier brand is neutral here (most offers carry no brand) — a filled
    supplier brand still needs the catalog brand to agree or be inferable
    from the title, and a catalog count of 0 is unknown, not a mismatch.
    A stale (قدیم) row never returns 'candidate': it needs a human
    confirmation. Variant-absent (iteration 2, item (b)): an identity match
    that is not EXACT — the catalog row carries a variant (or any other
    content token) the supplier never stated — never returns 'candidate'
    either: it returns 'needs_review' / 'variant_undetermined' with the
    rows listed for the owner, exactly like a stale row. A stated-and-
    agreeing variant is exact and still matches. Exactness never promotes
    a row out of ambiguity: one exact + one inexact row is
    'multiple_candidates' with both rows listed. compatibility_check=True
    (refresh reconcile only) keeps the old variant-neutral verdict: that
    call asks whether an ALREADY-DECIDED match drifted, not whether to
    assign a new one. Owner pins still win over both the stale and the
    variant rules (the pin block runs first). The
    'exact_compatible_identity' reason is kept: the kept-match choke
    point keys on it.
    """
    name = _name(product.get('clean_name') or product.get('name'))
    if not name:
        return {'state': 'needs_review', 'reason': 'missing_name', 'candidates': []}
    incoming = {
        'name': strip_compared_name(_name(product.get('clean_name') or product.get('name'))),
        'brand': alias_brand(_text(product.get('brand'))),
        'category': _text(product.get('category')),
        'variant': _text(product.get('variant') or product.get('variation_type')),
        'package_count': str(package_count(product) or ''),
        'volume': _name(product.get('volume')),
        'weight': _name(product.get('weight')),
    }
    raw_name = str(product.get('clean_name') or product.get('name') or '')
    # Lexicon for compound-splitting: standalone tokens from the passed
    # rows plus the incoming product's own tokens.
    lexicon = {}
    row_identities = []
    for row in rows:
        if not isinstance(row, (list, tuple)) or len(row) != len(HEADERS):
            return {'state': 'needs_review', 'reason': 'invalid_reference_rows', 'candidates': []}
        identity = identity_parse_title(str(row[0] or ''))
        for token in identity_parse_title(str(row[11] or ''))['tokens']:
            if token not in identity['tokens']:
                identity['tokens'].append(token)
        for token in identity['tokens']:
            lexicon[token] = True
        row_identities.append(identity)
    for token in identity_parse_title(raw_name)['tokens']:
        lexicon[token] = True
    candidates = []
    stale_candidates = []
    inexact_candidates = []
    for row, identity in zip(rows, row_identities):
        reference = _reference(row)
        catalog_tokens = identity_split_tokens(identity['tokens'], lexicon)
        agreement = identity_agreement(incoming, reference, catalog_tokens,
                                       bool(identity['stale']), lexicon, False, raw_name)
        if not agreement['identity_match']:
            continue
        if agreement['stale_packaging']:
            stale_candidates.append(reference)
        elif not agreement['identity_exact'] and not compatibility_check:
            inexact_candidates.append(reference)
        else:
            candidates.append(reference)
    pinned = (overrides or {}).get(product_signature(product))
    if isinstance(pinned, dict):
        selected = next((candidate for candidate in (_reference(row) for row in rows
            if isinstance(row, (list, tuple)) and len(row) == len(HEADERS))
            if candidate['reference_id'] == str(pinned.get('reference_id') or '')), None)
        if selected:
            selected['owner_price_role'] = str(pinned.get('price_role') or '')
            selected['owner_decision_id'] = str(pinned.get('decision_id') or '')
            return {'state': 'candidate', 'reason': 'owner_approved_mapping', 'candidates': [selected]}
    if not candidates and stale_candidates:
        return {'state': 'needs_review', 'reason': 'stale_packaging_reference',
                'candidates': stale_candidates}
    if not candidates and inexact_candidates:
        return {'state': 'needs_review', 'reason': 'variant_undetermined',
                'candidates': inexact_candidates}
    if not candidates:
        return {'state': 'needs_review', 'reason': 'no_compatible_reference', 'candidates': []}
    if len(candidates) + len(inexact_candidates) > 1:
        return {'state': 'needs_review', 'reason': 'multiple_candidates',
                'candidates': candidates + inexact_candidates}
    if not candidates[0]['reference_id']:
        return {'state': 'needs_review', 'reason': 'missing_reference_identity', 'candidates': candidates}
    return {'state': 'candidate', 'reason': 'exact_compatible_identity', 'candidates': candidates}



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
