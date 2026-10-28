"""Opt-in, staging-only Rezvan PDF importer. Never writes Sheets or activates data."""
import base64
import hashlib
import json
import math
import os
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE_URL = 'https://rezvanpakhsh.com/pdf/price-list.pdf'
HEADERS = ('Product Name', 'Clean Product Name', 'Normalized Key',
           'Reference Package Price', 'Reference Unit Price', 'Consumer Unit Price',
           'Package Count', 'Unit Label', 'Volume', 'Weight', 'Set Count', 'Variant',
           'Brand', 'Category', 'Raw Name Details', 'PDF Row Number', 'Product Code',
           'Currency', 'Source', 'Source URL', 'Last Updated')
DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')

class ImportFailure(ValueError):
    def __init__(self, message, *, code='extraction_invalid'):
        super().__init__(message)
        self.code = code


def number(value):
    if value in (None, ''):
        return 0
    if isinstance(value, bool):
        raise ImportFailure('Boolean numeric field', code='numeric_invalid')
    text = str(value).translate(DIGITS).replace(',', '').replace('٬', '').strip()
    if not re.fullmatch(r'\d+(?:\.\d+)?', text):
        raise ImportFailure('Invalid or ambiguous numeric field', code='numeric_invalid')
    result = float(text)
    if not math.isfinite(result):
        raise ImportFailure('Nonfinite numeric field', code='numeric_invalid')
    return result


def normalize_name(value):
    text = value.translate(DIGITS).replace('ي', 'ی').replace('ك', 'ک')
    return re.sub(r'\s+', ' ', re.sub(r'[^\w\s]', ' ', text)).strip().lower()


def _gemini_http_error_code(response):
    status_code=getattr(response,'status_code',0)
    code=f'gemini_http_{status_code}' if isinstance(status_code,int) and 100<=status_code<=599 else 'gemini_http_error'
    try:
        error=response.json().get('error',{})
        status=str(error.get('status') or '').upper()
        message=str(error.get('message') or '').lower()
        reasons={str(item.get('reason') or '').upper() for item in error.get('details',[])
                 if isinstance(item,dict)}
    except (AttributeError,TypeError,ValueError):
        return code
    if ('API_KEY_INVALID' in reasons or 'API_KEY_INVALID' in status
            or ('api key' in message and ('invalid' in message or 'not valid' in message))):
        return 'gemini_key_invalid'
    mapped={'RESOURCE_EXHAUSTED':'gemini_rate_limited','PERMISSION_DENIED':'gemini_permission_denied',
        'NOT_FOUND':'gemini_model_not_found','INVALID_ARGUMENT':'gemini_invalid_argument',
        'FAILED_PRECONDITION':'gemini_precondition_failed'}
    for reason in reasons:
        if reason in {'API_KEY_SERVICE_BLOCKED','SERVICE_DISABLED'}:
            return 'gemini_service_disabled'
    return mapped.get(status,code)


def parse_rows(ai_text, timestamp):
    """Fail the whole candidate on invalid rows; never silently drop a product."""
    try:
        text = ai_text.strip()
        if text.startswith('```json') and text.endswith('```'):
            text = text[7:-3].strip()
        items = json.loads(text)
    except (ValueError, AttributeError) as exc:
        raise ImportFailure('Invalid extraction JSON', code='extraction_invalid_json') from exc
    if isinstance(items, dict):
        arrays = [items[k] for k in ('items', 'products', 'references', 'reference_prices',
                                    'rows', 'data', 'price_list') if isinstance(items.get(k), list)]
        if len(arrays) != 1:
            raise ImportFailure('Ambiguous extraction envelope', code='extraction_ambiguous_envelope')
        items = arrays[0]
    if not isinstance(items, list) or not items:
        raise ImportFailure('Empty or non-array extraction', code='extraction_empty')
    rows, seen = [], set()
    for item in items:
        if not isinstance(item, dict):
            raise ImportFailure('Invalid product object', code='extraction_invalid_product')
        raw = item.get('raw_name')
        clean = item.get('clean_name') or raw
        if not isinstance(raw, str) or not isinstance(clean, str) or not normalize_name(clean):
            raise ImportFailure('Missing product name', code='extraction_missing_name')
        if item.get('currency', 'TOMAN') != 'TOMAN':
            raise ImportFailure('Unexpected currency; conversion requires explicit policy', code='extraction_currency_mismatch')
        package = number(item.get('package_price'))
        count = number(item.get('package_count'))
        supplied_unit = number(item.get('unit_price'))
        consumer = number(item.get('consumer_unit_price'))
        set_count = number(item.get('set_count'))
        if package <= 0 or count != int(count) or set_count != int(set_count):
            raise ImportFailure('Invalid package price or count', code='extraction_package_invalid')
        # Derive only from an explicit count; model-supplied unit prices cannot override it.
        unit = math.floor(package / count + .5) if count else 0
        if supplied_unit and (not count or abs(supplied_unit - unit) > 1):
            raise ImportFailure('Inconsistent unit price', code='extraction_unit_inconsistent')
        hint = re.search(r'[（(]\s*(\d{1,4})\s*ف\s*[）)]', raw.translate(DIGITS))
        if hint:
            retail = int(hint.group(1)) * 1000
            if consumer and consumer != retail:
                raise ImportFailure('Conflicting retail hint', code='extraction_retail_conflict')
            consumer = retail
        code = str(item.get('product_code') or '').strip()
        key = normalize_name(clean)
        attributes=(key,str(count),str(set_count),str(item.get('unit_label') or ''),
                    *(str(item.get(k) or '') for k in ('brand','variant','volume','weight')))
        # A supplier code can cover distinct pack sizes/variants; use it as one
        # identity component instead of treating it as globally unique.
        identity=('code',code,*attributes) if code else ('attributes',*attributes)
        if identity in seen:
            raise ImportFailure('Duplicate reference identity', code='extraction_duplicate_identity')
        seen.add(identity)
        values = [raw, clean, key, package, unit or '', consumer or '', count or '',
                  *(item.get(k) or '' for k in ('unit_label', 'volume', 'weight')),
                  set_count or '', *(item.get(k) or '' for k in ('variant', 'brand', 'category',
                  'raw_name_details', 'pdf_row_number')), code, 'TOMAN',
                  'rezvanpakhsh_pdf_ai', SOURCE_URL, timestamp]
        if any(isinstance(v, (dict, list, bool)) for v in values):
            raise ImportFailure('Invalid structured column value', code='extraction_field_invalid')
        rows.append(values)
    return rows


def extract_pdf(session, api_key=None, model=None, *, max_pdf_bytes=15_000_000):
    """Explicit invocation only; credentials/models default to server environment."""
    from catalog.gemini import DEFAULT_GEMINI_MODEL, gemini_model, google_api_key

    api_key = (api_key or google_api_key()).strip()
    model = model or gemini_model() or DEFAULT_GEMINI_MODEL
    if not api_key or not re.fullmatch(r'[A-Za-z0-9._-]+', model or ''):
        raise ImportFailure('Google API key and valid Gemini model required', code='configuration_missing')
    # Stream and bound the PDF before allocating the base64 request.
    with session.get(SOURCE_URL, timeout=(10, 60), stream=True) as response:
        if response.status_code != 200:
            raise ImportFailure('PDF download failed', code=f'source_http_{response.status_code}')
        chunks, size = [], 0
        for chunk in response.iter_content(65536):
            size += len(chunk)
            if size > max_pdf_bytes:
                raise ImportFailure('PDF exceeds configured size limit', code='source_too_large')
            chunks.append(chunk)
    pdf = b''.join(chunks)
    if not pdf.startswith(b'%PDF-'):
        raise ImportFailure('Source is not a PDF', code='source_not_pdf')
    prompt = Path(__file__).with_name('prompt.txt').read_text()
    response = session.post(
        f'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
        headers={'x-goog-api-key': api_key}, timeout=(10, 120),
        json={'contents': [{'parts': [{'text': prompt}, {'inline_data': {
            'mime_type': 'application/pdf', 'data': base64.b64encode(pdf).decode()}}]}],
              'generationConfig': {'temperature': 0, 'responseMimeType': 'application/json'}})
    if response.status_code != 200:
        raise ImportFailure('Gemini request failed', code=_gemini_http_error_code(response))
    try:
        candidates = response.json()['candidates']
        if len(candidates) != 1 or candidates[0].get('finishReason') != 'STOP':
            raise ImportFailure('Incomplete or ambiguous Gemini extraction', code='gemini_incomplete')
        parts = candidates[0]['content']['parts']
        text = ''.join(p['text'] for p in parts if p.get('text') and not p.get('thought'))
    except (KeyError, TypeError, ValueError) as exc:
        raise ImportFailure('Invalid Gemini response', code='gemini_response_invalid') from exc
    now = datetime.now(timezone.utc).isoformat()
    rows = parse_rows(text, now)
    return {'schema_version': 1, 'status': 'staged', 'requires_review': True,
            'source_url': SOURCE_URL, 'pdf_sha256': hashlib.sha256(pdf).hexdigest(),
            'model': model, 'extracted_at': now, 'row_count': len(rows),
            'headers': HEADERS, 'rows': rows}


def save_candidate(candidate, destination):
    """Atomic local candidate write. Destination must never be an active snapshot."""
    destination = Path(destination)
    if destination.exists():
        raise ImportFailure('Candidate destination already exists', code='candidate_exists')
    data = json.dumps(candidate, ensure_ascii=False, allow_nan=False, indent=2)
    fd, temporary = tempfile.mkstemp(prefix='.reference-', dir=destination.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        # Hard-link provides atomic create-without-overwrite even for concurrent writers.
        os.link(temporary, destination)
    finally:
        os.unlink(temporary)
