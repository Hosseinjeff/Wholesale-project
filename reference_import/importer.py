"""Staging-only imports from Rezvan Pakhsh's public WooCommerce Store API."""
import base64
import copy
import hashlib
import io
import json
import math
import os
import re
import tempfile
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path

from catalog.measurements import extract_measurement, normalize_measurement_tokens

SOURCE_URL = 'https://rezvanpakhsh.com/wp-json/wc/store/v1/products'
PDF_SOURCE_URL = 'https://rezvanpakhsh.com/pdf/price-list.pdf'
SOURCE_TYPE = 'woocommerce_store_api'
SOURCE_NAME = 'rezvanpakhsh_woocommerce_store_api'
LEGACY_PDF_SOURCE_NAME = 'rezvanpakhsh_pdf_ai'
HEADERS = ('Product Name', 'Clean Product Name', 'Normalized Key',
           'Reference Package Price', 'Reference Unit Price', 'Consumer Unit Price',
           'Package Count', 'Unit Label', 'Volume', 'Weight', 'Set Count', 'Variant',
           'Brand', 'Category', 'Raw Name Details', 'Source Row Number', 'Product Code',
           'Currency', 'Source', 'Source URL', 'Last Updated')
LEGACY_PDF_HEADERS = tuple('PDF Row Number' if header == 'Source Row Number' else header
                           for header in HEADERS)
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


def title_package_count(raw_name):
    """Read a count only when the product title labels it explicitly."""
    title = raw_name.translate(DIGITS)
    matches = re.findall(r'(?<!\d)(\d{1,4})\s*(?:عددی|عدد|ع)(?=$|[^\w])', title)
    if matches:
        return int(matches[-1])
    carton = re.search(r'(?:کارتن|باکس|بسته)\s*(\d{1,4})(?=$|[^\w])', title)
    return int(carton.group(1)) if carton else None


def title_set_count(raw_name):
    """Keep a title's N-piece/set marker out of the package-price denominator."""
    matches = re.findall(r'(?<!\d)(\d{1,3})\s*تایی(?=$|[^\w])', raw_name.translate(DIGITS))
    return int(matches[-1]) if matches else None


def clean_title_for_matching(raw_name):
    """Remove explicit package/retail markers from the match name, keeping the source title intact."""
    title = normalize_measurement_tokens(str(raw_name or ''))
    title = re.sub(r'[（(]\s*\d{1,4}\s*ف\s*[）)]', ' ', title.translate(DIGITS))
    title = re.sub(r'(?<!\d)\d{1,4}\s*(?:عددی|عدد|ع)(?=$|[^\w])', ' ', title)
    title = re.sub(r'(?:کارتن|باکس|بسته)\s*\d{1,4}(?=$|[^\w])', ' ', title)
    title = re.sub(r'[^\w\s.-]', ' ', title)
    return re.sub(r'\s+', ' ', title).strip()


def _title_measurements(raw_name, *, volume):
    """Extract only explicit volume/weight tokens; keep the full title separately."""
    return extract_measurement(raw_name, 'volume' if volume else 'weight')


def _woocommerce_attribute_values(product):
    """Read a small set of explicit API attributes without guessing from a title."""
    result = {'brand': '', 'variant': ''}
    attributes = product.get('attributes') or []
    if not isinstance(attributes, list):
        return result
    for item in attributes:
        if not isinstance(item, dict):
            continue
        label = str(item.get('name') or item.get('taxonomy') or '').replace('_', ' ')
        key = normalize_name(label)
        raw_value = item.get('value')
        if raw_value in (None, ''):
            raw_value = item.get('terms')
        if isinstance(raw_value, list):
            values = []
            for value in raw_value:
                if isinstance(value, dict):
                    value = value.get('name') or value.get('slug') or ''
                if isinstance(value, (str, int, float)) and not isinstance(value, bool):
                    value = str(value).strip()
                    if value and value not in values:
                        values.append(value)
            value = '، '.join(values)
        elif isinstance(raw_value, (str, int, float)) and not isinstance(raw_value, bool):
            value = str(raw_value).strip()
        else:
            value = ''
        if not value:
            continue
        if key in {'brand', 'brands', 'برند', 'نام برند'} or key.endswith(' brand'):
            result['brand'] = value
        elif key in {'variant', 'flavor', 'taste', 'نوع', 'طعم', 'مدل', 'رنگ'}:
            result['variant'] = value
    return result


def _woocommerce_toman_price(prices):
    if not isinstance(prices, dict):
        raise ImportFailure('WooCommerce price is missing', code='woocommerce_prices_missing')
    currency = str(prices.get('currency_code') or '').strip().upper()
    if currency not in {'IRR', 'IRT'}:
        raise ImportFailure('WooCommerce currency is unsupported', code='woocommerce_currency_unsupported')
    minor = prices.get('currency_minor_unit', 0)
    if isinstance(minor, bool) or not isinstance(minor, int) or not 0 <= minor <= 4:
        raise ImportFailure('WooCommerce currency precision is invalid', code='woocommerce_currency_precision_invalid')
    raw = prices.get('price')
    if isinstance(raw, bool) or raw in (None, ''):
        raise ImportFailure('WooCommerce price is missing', code='woocommerce_prices_missing')
    try:
        value = Decimal(str(raw))
    except (InvalidOperation, ValueError):
        raise ImportFailure('WooCommerce price is invalid', code='woocommerce_price_invalid') from None
    if not value.is_finite() or value <= 0:
        raise ImportFailure('WooCommerce price is invalid', code='woocommerce_price_invalid')
    value /= Decimal(10) ** minor
    if currency == 'IRR':
        value /= Decimal(10)
    toman = int(value.quantize(Decimal('1'), rounding=ROUND_HALF_UP))
    if toman <= 0:
        raise ImportFailure('WooCommerce price is below one toman', code='woocommerce_price_invalid')
    return toman, currency


def _woocommerce_row(product, row_number, timestamp):
    if not isinstance(product, dict):
        raise ImportFailure('Invalid WooCommerce product object', code='woocommerce_product_invalid')
    product_id = product.get('id')
    if isinstance(product_id, bool) or not isinstance(product_id, int) or product_id < 1:
        raise ImportFailure('WooCommerce product ID is invalid', code='woocommerce_product_id_invalid')
    raw_name = product.get('name')
    if not isinstance(raw_name, str) or not raw_name.strip():
        raise ImportFailure('WooCommerce product title is missing', code='woocommerce_product_title_missing')
    raw_name = raw_name.strip()
    clean_name = clean_title_for_matching(raw_name)
    key = normalize_name(clean_name)
    if not key:
        raise ImportFailure('WooCommerce product title is invalid', code='woocommerce_product_title_missing')
    sku = product.get('sku')
    if sku is None or isinstance(sku, (dict, list, bool)):
        raise ImportFailure('WooCommerce SKU is invalid', code='woocommerce_sku_invalid')
    code = str(sku).strip() or f'woo-{product_id}'
    package_price, currency = _woocommerce_toman_price(product.get('prices'))
    package_count = title_package_count(raw_name) or 0
    set_count = title_set_count(raw_name) or 0
    unit_price = ((package_price + package_count // 2) // package_count) if package_count else ''
    retail = re.findall(r'[（(]\s*(\d{1,4})\s*ف\s*[）)]', raw_name.translate(DIGITS))
    consumer_price = int(retail[-1]) * 1000 if retail else ''
    categories = product.get('categories') or []
    category_names = []
    if isinstance(categories, list):
        for category in categories:
            if isinstance(category, dict) and isinstance(category.get('name'), str):
                name = category['name'].strip()
                if name and name not in category_names:
                    category_names.append(name)
    attributes = _woocommerce_attribute_values(product)
    return [raw_name, clean_name, key, package_price, unit_price, consumer_price,
            package_count or '', 'عدد' if package_count else '',
            _title_measurements(raw_name, volume=True),
            _title_measurements(raw_name, volume=False), set_count or '',
            attributes['variant'], attributes['brand'], ' / '.join(category_names),
            '', str(row_number), code, 'TOMAN', SOURCE_NAME, SOURCE_URL, timestamp]


def _build_woocommerce_candidate(products, page_count, source_currency, timestamp):
    rows = [_woocommerce_row(product, index, timestamp)
            for index, product in enumerate(products, 1)]
    return {'schema_version': 1, 'status': 'staged', 'requires_review': True,
            'source_type': SOURCE_TYPE, 'source_url': SOURCE_URL,
            'source_currency': source_currency, 'source_total': len(rows),
            'source_page_count': page_count, 'processed_page_count': page_count,
            'first_source_row_number': 1, 'last_source_row_number': len(rows),
            'model': None, 'extracted_at': timestamp, 'row_count': len(rows),
            'coverage_verified': True, 'headers': HEADERS, 'rows': rows}


def _fetch_woocommerce_page(get, page, *, per_page, expected_total=None, expected_pages=None):
    params = {'per_page': per_page, 'page': page, 'orderby': 'id', 'order': 'asc'}
    for attempt in range(4):
        try:
            response = get(SOURCE_URL, params=params, timeout=(10, 45))
        except Exception as exc:
            if attempt < 3:
                time.sleep(min(8, 2 ** attempt))
                continue
            raise ImportFailure('WooCommerce request failed', code='woocommerce_transport_failed') from exc
        status = getattr(response, 'status_code', 0)
        if status in (429, 500, 502, 503, 504) and attempt < 3:
            try:
                delay = min(15, max(1, int(float(response.headers.get('Retry-After', '0')))))
            except (AttributeError, TypeError, ValueError):
                delay = min(8, 2 ** attempt)
            time.sleep(delay)
            continue
        if status != 200:
            code = f'woocommerce_http_{status}' if isinstance(status, int) and 100 <= status <= 599 else 'woocommerce_http_error'
            raise ImportFailure('WooCommerce products could not be read', code=code)
        headers = getattr(response, 'headers', {})
        try:
            total = int(headers.get('X-WP-Total', ''))
            pages = int(headers.get('X-WP-TotalPages', ''))
        except (TypeError, ValueError):
            raise ImportFailure('WooCommerce pagination headers are missing',
                                code='woocommerce_pagination_invalid') from None
        if total < 1 or pages < 1 or pages > 500:
            raise ImportFailure('WooCommerce pagination range is invalid',
                                code='woocommerce_pagination_invalid')
        if ((expected_total is not None and total != expected_total)
                or (expected_pages is not None and pages != expected_pages)):
            raise ImportFailure('WooCommerce product list changed during pagination',
                                code='woocommerce_pagination_changed')
        try:
            products = response.json()
        except Exception as exc:
            raise ImportFailure('WooCommerce returned invalid JSON',
                                code='woocommerce_json_invalid') from exc
        if not isinstance(products, list):
            raise ImportFailure('WooCommerce returned an invalid page',
                                code='woocommerce_page_invalid')
        expected_count = min(per_page, max(0, total - (page - 1) * per_page))
        if len(products) != expected_count:
            raise ImportFailure('WooCommerce product page was incomplete',
                                code=f'woocommerce_page_incomplete_{page}')
        return products, total, pages
    raise ImportFailure('WooCommerce products could not be read', code='woocommerce_http_error')


def extract_woocommerce_store_api(session=None, *, per_page=100):
    """Fetch every published product and stage a deterministic, review-only candidate."""
    if isinstance(per_page, bool) or not isinstance(per_page, int) or not 1 <= per_page <= 100:
        raise ImportFailure('WooCommerce page size is invalid', code='woocommerce_pagination_invalid')
    if session is None:
        import requests
        thread_sessions = threading.local()

        def get(url, **kwargs):
            client = getattr(thread_sessions, 'client', None)
            if client is None:
                client = requests.Session()
                thread_sessions.client = client
            return client.get(url, **kwargs)
    else:
        get = session.get

    first, total, page_count = _fetch_woocommerce_page(get, 1, per_page=per_page)
    pages = [first]
    if page_count > 1:
        if session is not None:
            for page in range(2, page_count + 1):
                products, _, _ = _fetch_woocommerce_page(get, page, per_page=per_page,
                    expected_total=total, expected_pages=page_count)
                pages.append(products)
        else:
            with ThreadPoolExecutor(max_workers=8) as pool:
                futures = {pool.submit(_fetch_woocommerce_page, get, page, per_page=per_page,
                    expected_total=total, expected_pages=page_count): page
                    for page in range(2, page_count + 1)}
                fetched = {}
                try:
                    for future in as_completed(futures):
                        fetched[futures[future]] = future.result()[0]
                except ImportFailure:
                    for future in futures:
                        future.cancel()
                    raise
            pages.extend(fetched[page] for page in range(2, page_count + 1))
    products = [product for page in pages for product in page]
    if len(products) != total:
        raise ImportFailure('WooCommerce product coverage is incomplete',
                            code='woocommerce_product_coverage_incomplete')
    ids, skus = set(), set()
    currencies = set()
    for product in products:
        if not isinstance(product, dict):
            raise ImportFailure('Invalid WooCommerce product object', code='woocommerce_product_invalid')
        product_id = product.get('id')
        if isinstance(product_id, bool) or not isinstance(product_id, int) or product_id < 1:
            raise ImportFailure('WooCommerce product ID is invalid', code='woocommerce_product_id_invalid')
        if product_id in ids:
            raise ImportFailure('Duplicate WooCommerce product ID', code='woocommerce_duplicate_product_id')
        ids.add(product_id)
        sku = product.get('sku')
        if sku is not None and isinstance(sku, (dict, list, bool)):
            raise ImportFailure('WooCommerce SKU is invalid', code='woocommerce_sku_invalid')
        if sku not in (None, ''):
            sku_key = str(sku).strip().casefold()
            if sku_key in skus:
                raise ImportFailure('Duplicate WooCommerce product SKU', code='woocommerce_duplicate_sku')
            skus.add(sku_key)
        _, currency = _woocommerce_toman_price(product.get('prices'))
        currencies.add(currency)
    if len(currencies) != 1:
        raise ImportFailure('WooCommerce products use inconsistent currencies',
                            code='woocommerce_currency_mismatch')
    extracted_at = datetime.now(timezone.utc).isoformat()
    candidate = _build_woocommerce_candidate(products, page_count, next(iter(currencies)), extracted_at)
    if candidate['source_total'] != total:
        raise ImportFailure('WooCommerce product coverage is incomplete',
                            code='woocommerce_product_coverage_incomplete')
    candidate['processed_page_count'] = len(pages)
    return candidate


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


def parse_rows(ai_text, timestamp, *, allow_empty=False):
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
    if isinstance(items, list) and not items and allow_empty:
        return []
    if not isinstance(items, list) or not items:
        raise ImportFailure('Empty or non-array extraction', code='extraction_empty')
    rows = []
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
        title_count = title_package_count(raw)
        count = title_count or 0
        consumer = 0
        title_set = title_set_count(raw)
        set_count = title_set or 0
        if package <= 0:
            raise ImportFailure('Invalid package price or count', code='extraction_package_invalid')
        # Counts and retail hints affect prices only when they appear explicitly in the title.
        unit = math.floor(package / count + .5) if count else 0
        hint = re.search(r'[（(]\s*(\d{1,4})\s*ف\s*[）)]', raw.translate(DIGITS))
        if hint:
            retail = int(hint.group(1)) * 1000
            # The printed title marker is explicit; derive the retail amount from it.
            consumer = retail
        code = str(item.get('product_code') or '').strip()
        row_number = str(item.get('pdf_row_number') or '').translate(DIGITS).strip()
        if not re.fullmatch(r'\d{1,6}', row_number) or int(row_number) < 1:
            raise ImportFailure('Missing or invalid printed row number', code='extraction_row_number_invalid')
        key = normalize_name(clean)
        # Keep repeated product identities when they occupy distinct printed PDF
        # rows. A repeated row number is the reliable signal for duplicated output.
        if any(str(existing[15]) == row_number for existing in rows):
            raise ImportFailure('Duplicate printed row number',
                                code=f'extraction_duplicate_row_number_{row_number}')
        def text_value(field):
            value = item.get(field) or ''
            if isinstance(value, (dict, list, bool)):
                raise ImportFailure('Invalid structured text field', code='extraction_field_invalid')
            return str(value).strip()
        raw_details = text_value('raw_name_details')
        blade_markers = re.findall(r'(?<!\d)\d{1,3}\s*لبه(?=$|[^\w])', raw)
        for marker in blade_markers:
            if normalize_name(marker) not in normalize_name(raw_details):
                raw_details = ' '.join(part for part in (raw_details, marker) if part)
        unit_label=text_value('unit_label') or ('عدد' if title_count is not None else '')
        values = [raw, clean, key, package, unit or '', consumer or '', count or '',
                  unit_label, *(text_value(k) for k in ('volume', 'weight')),
                  set_count or '', *(text_value(k) for k in ('variant', 'brand', 'category',
                  )), raw_details, row_number, code, 'TOMAN',
                  LEGACY_PDF_SOURCE_NAME, PDF_SOURCE_URL, timestamp]
        if any(isinstance(v, (dict, list, bool)) for v in values):
            raise ImportFailure('Invalid structured column value', code='extraction_field_invalid')
        rows.append(values)
    return rows


def _pdf_page_batches(pdf):
    """Create one Gemini request per cropped table half."""
    try:
        from pypdf import PdfReader, PdfWriter
    except ImportError as exc:
        raise ImportFailure('PDF table splitting is unavailable', code='pdf_split_unavailable') from exc
    try:
        reader = PdfReader(io.BytesIO(pdf), strict=False)
        if reader.is_encrypted:
            raise ImportFailure('Encrypted source PDF', code='source_pdf_encrypted')
        page_count = len(reader.pages)
        if page_count < 1 or page_count > 120:
            raise ImportFailure('Unexpected source PDF page count', code='source_page_count_invalid')
        batches = []
        for page_index, source_page in enumerate(reader.pages):
            if source_page.rotation % 360:
                raise ImportFailure('Rotated source pages need review', code='source_page_rotation')
            left = float(source_page.mediabox.left)
            bottom = float(source_page.mediabox.bottom)
            right = float(source_page.mediabox.right)
            top = float(source_page.mediabox.top)
            midpoint = left + (right - left) / 2
            for half, bounds in (('left', (left, bottom, midpoint, top)),
                                 ('right', (midpoint, bottom, right, top))):
                writer = PdfWriter()
                page = copy.copy(source_page)
                page.cropbox.lower_left = (bounds[0], bounds[1])
                page.cropbox.upper_right = (bounds[2], bounds[3])
                writer.add_page(page)
                output = io.BytesIO()
                writer.write(output)
                batches.append((page_index, half, output.getvalue()))
        return page_count, batches
    except ImportFailure:
        raise
    except Exception as exc:
        raise ImportFailure('Could not split the source PDF into tables', code='source_pdf_split_failed') from exc


def _extract_pdf_batch(batch_pdf, *, api_key, model, prompt, source_page_number,
                       table_half, allow_partial=False):
    import requests

    instruction = (
        f'This attachment contains exactly one cropped product table: the {table_half} half of original PDF '
        f'page {source_page_number}. It does not contain the other table half. '
        'Read this crop only; do not assume data from another page or table. '
        'Read every visible product row exactly once, including the printed row number. Match its price, code, '
        'and title only within that row. Copy the full title and preserve its details in the separate fields. '
        'Leave uncertain title attributes blank and keep the uncertainty in raw_name_details. Set unit_price to 0; '
        'the server calculates it from package_price and package_count. '
        + ('This table crop may be partially filled. If it has no product rows, return [] exactly; do not invent rows.'
           if allow_partial else 'This table crop contains exactly 30 product rows.')
    )
    payload = {
        'contents': [{'parts': [{'text': prompt + '\n\n' + instruction}, {'inline_data': {
            'mime_type': 'application/pdf', 'data': base64.b64encode(batch_pdf).decode('ascii')}}]}],
        'generationConfig': {'temperature': 0, 'responseMimeType': 'application/json', 'maxOutputTokens': 32768},
    }
    endpoint = f'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent'
    for attempt in range(5):
        try:
            response = requests.post(endpoint, headers={'x-goog-api-key': api_key},
                                     timeout=(10, 180), json=payload)
        except requests.RequestException as exc:
            if attempt < 4:
                time.sleep(min(20, 2 ** (attempt + 1)))
                continue
            raise ImportFailure('Gemini request failed', code='gemini_transport_failed') from exc
        if response.status_code in (429, 500, 502, 503, 504) and attempt < 4:
            try:
                retry_after = min(30, max(1, int(float(response.headers.get('Retry-After', '0')))))
            except (AttributeError, TypeError, ValueError):
                retry_after = min(20, 2 ** (attempt + 1))
            time.sleep(retry_after)
            continue
        if response.status_code != 200:
            raise ImportFailure('Gemini request failed', code=_gemini_http_error_code(response))
        try:
            candidates = response.json()['candidates']
            if len(candidates) != 1 or candidates[0].get('finishReason') != 'STOP':
                raise ImportFailure('Incomplete or ambiguous Gemini extraction', code='gemini_incomplete')
            parts = candidates[0]['content']['parts']
            text = ''.join(p['text'] for p in parts if p.get('text') and not p.get('thought'))
            if not text:
                raise ImportFailure('Empty Gemini extraction', code='extraction_empty')
            return text
        except ImportFailure:
            raise
        except (KeyError, TypeError, ValueError) as exc:
            raise ImportFailure('Invalid Gemini response', code='gemini_response_invalid') from exc
    raise ImportFailure('Gemini request failed', code='gemini_rate_limited')


def _verified_row_numbers(rows, *, page_count):
    numbers = []
    for row in rows:
        value = str(row[15]).translate(DIGITS)
        if not value.isdigit() or int(value) < 1:
            raise ImportFailure('Missing printed PDF row number', code='extraction_row_number_invalid')
        numbers.append(int(value))
    if not numbers or numbers[0] != 1 or len(numbers) != len(set(numbers)):
        raise ImportFailure('PDF row coverage is incomplete', code='extraction_row_sequence_invalid')
    if numbers != list(range(1, max(numbers) + 1)):
        raise ImportFailure('PDF rows contain gaps', code='extraction_row_gap')
    if len(numbers) < page_count * 30:
        raise ImportFailure('Too few products were extracted from the source PDF',
                            code='extraction_too_few_rows')
    return numbers


def extract_pdf(session, api_key=None, model=None, *, max_pdf_bytes=15_000_000):
    """Extract cropped table batches and stage only a coverage-checked candidate."""
    from catalog.gemini import google_api_key

    api_key = (api_key or google_api_key()).strip()
    model = (model or os.getenv('REFERENCE_GEMINI_MODEL') or 'gemini-3.5-flash-lite').strip()
    if not api_key or not re.fullmatch(r'[A-Za-z0-9._-]+', model or ''):
        raise ImportFailure('Google API key and valid reference model required', code='configuration_missing')
    with session.get(PDF_SOURCE_URL, timeout=(10, 60), stream=True) as response:
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
    page_count, batches = _pdf_page_batches(pdf)
    prompt = Path(__file__).with_name('prompt.txt').read_text(encoding='utf-8')
    extracted_at = datetime.now(timezone.utc).isoformat()

    def run(batch):
        page_index, half, batch_pdf = batch
        allow_partial = page_index >= max(0, page_count - 2)
        expected_numbers = None
        if page_index < page_count - 2:
            expected_start = page_index * 60 + (1 if half == 'left' else 31)
            expected_numbers = list(range(expected_start, expected_start + 30))
        retryable_prefixes = (
            'extraction_duplicate_row_number_', 'extraction_invalid_json',
            'extraction_missing_name', 'extraction_row_number_invalid',
            'extraction_field_invalid', 'extraction_package_invalid',
            'extraction_currency_mismatch', 'extraction_empty', 'numeric_invalid',
            'extraction_batch_row_count_mismatch', 'extraction_batch_row_numbers_mismatch',
        )
        last_failure = None
        for attempt in range(2):
            attempt_prompt = prompt
            if attempt:
                correction = ('The previous response failed a structural check. Re-read this same crop from '
                              'scratch. Return each visible product exactly once, with one unique printed row '
                              'number per row. Do not invent, repeat, or skip a row number.')
                if expected_numbers:
                    correction += (f' The required printed row numbers for this crop are '
                                  f'{expected_numbers[0]} through {expected_numbers[-1]}.')
                attempt_prompt += '\n\n' + correction
            try:
                text = _extract_pdf_batch(batch_pdf, api_key=api_key, model=model,
                                          prompt=attempt_prompt, source_page_number=page_index + 1,
                                          table_half=half, allow_partial=allow_partial)
                rows = parse_rows(text, extracted_at, allow_empty=allow_partial)
                if expected_numbers is not None:
                    if len(rows) != 30:
                        raise ImportFailure('A PDF table batch did not return every product row',
                                            code='extraction_batch_row_count_mismatch')
                    numbers = [int(row[15]) for row in rows]
                    if sorted(numbers) != expected_numbers:
                        raise ImportFailure('A PDF table batch has missing or mismatched row numbers',
                                            code='extraction_batch_row_numbers_mismatch')
                return page_index, half, rows
            except ImportFailure as exc:
                last_failure = exc
                can_retry = (exc.code in ('gemini_incomplete', 'gemini_response_invalid')
                             or exc.code.startswith(retryable_prefixes))
                if attempt == 0 and can_retry:
                    continue
                raise ImportFailure(str(exc),
                                    code=f'{exc.code}_page_{page_index + 1}_{half}') from exc
        raise last_failure

    try:
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(run, batch) for batch in batches]
            results = []
            try:
                for future in as_completed(futures):
                    results.append(future.result())
            except ImportFailure:
                for future in futures:
                    future.cancel()
                raise
    except ImportFailure:
        raise
    if not any(batch_rows for page_index, _, batch_rows in results if page_index == page_count - 1):
        raise ImportFailure('No product rows were extracted from the final PDF page',
                            code=f'extraction_final_page_empty_page_{page_count}')
    rows = [row for _, _, batch_rows in sorted(results) for row in batch_rows]
    rows.sort(key=lambda row: int(row[15]))
    numbers = _verified_row_numbers(rows, page_count=page_count)
    return {'schema_version': 1, 'status': 'staged', 'requires_review': True,
            'source_type': 'legacy_pdf_gemini', 'source_url': PDF_SOURCE_URL,
            'pdf_sha256': hashlib.sha256(pdf).hexdigest(),
            'model': model, 'extracted_at': extracted_at, 'row_count': len(rows),
            'source_page_count': page_count, 'processed_page_count': page_count,
            'first_pdf_row_number': numbers[0], 'last_pdf_row_number': numbers[-1],
            'coverage_verified': True, 'headers': HEADERS, 'rows': rows}


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
