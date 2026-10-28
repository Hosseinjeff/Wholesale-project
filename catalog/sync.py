"""Write catalog batches straight to Google Sheets with a service account.

Offers is the append-only price log. Products is the current book: one row
per supplier and product name, rebuilt from that log. Items holds an optional confirmed name and sell price. A clear price is
saved without a question. Decisions and the bot ask only when the unit
price is missing, the gap looks wrong, or the read is otherwise in doubt.
The Apps Script webhook is a legacy ingest path and does not write these sheets.
"""

from __future__ import annotations

import json
import logging
import os
import re
import threading
import time
from typing import Any
from urllib.parse import quote

from catalog.extract import (
    BATCH_STATUS_HEADERS,
    EXECUTION_LOG_HEADERS,
    DECISION_HEADERS,
    ITEM_HEADERS,
    PROMPT_HEADERS,
    MESSAGE_HEADERS,
    OFFER_HEADERS,
    PRODUCT_HEADERS,
    classify_message,
    extract_products,
    minimum_purchase,
    normalize,
    parse_price,
    peel_title_pack,
    quality_check,
)
from catalog.media import share_album_images
from catalog.gemini import GeminiError, ai_extraction_enabled, extract_message_products
from catalog.sheet_reconcile import field_values_equal, reconcile_field, stable_conflict_id
from catalog.pricing import (
    SHOP_FORMULA_ROW,
    SHOP_HEADER_ROW,
    SHOP_HEADERS,
    catalog_from_records,
    formula_separator,
    settings_from_rows,
    shop_formulas,
    shop_setting_rows,
)

logger = logging.getLogger(__name__)

_WRITE_LOCK = threading.Lock()
SHEETS_SCOPE = "https://www.googleapis.com/auth/spreadsheets"
_PRESERVE_ON_UPDATE = ("Product ID", "Import Timestamp")
SHEET_PROVENANCE_FIELDS={
    'Product ID','Channel ID','Channel','Channel Username','Original Message','Message Timestamp',
    'Forwarded By','Import Timestamp','Last Updated','Batch ID','Latest Offer ID','Offer Count',
    'Previous Sale Price','Previous Consumer Price','Price Changed At','Image File Unique ID',
    'Reference Snapshot ID','Reference Match ID','Reference Decision ID',
}
_SHEET_IMMUTABLE_FIELDS=SHEET_PROVENANCE_FIELDS


def extend_header_row(current: list[Any], required: list[str]) -> tuple[list[str], bool]:
    """Append catalog columns that an existing sheet does not have yet."""
    headers = [str(header) for header in current]
    changed = False
    for header in required:
        if header not in headers:
            headers.append(header)
            changed = True
    return headers, changed


class MemoryStore:
    """In-memory sheet used by tests and as the store contract."""

    def __init__(self) -> None:
        self.sheets: dict[str, list[list[Any]]] = {}

    def ensure(self, name: str, headers: list[str]) -> None:
        table = self.sheets.get(name)
        if not table:
            self.sheets[name] = [list(headers)]
            return
        extended, changed = extend_header_row(table[0], headers)
        if changed:
            table[0] = extended

    def read(self, name: str) -> list[list[Any]]:
        return [list(row) for row in self.sheets.get(name, [])]

    def read_fresh(self,name: str) -> list[list[Any]]:
        return self.read(name)

    def write_cells(self,name: str,row_number: int,headers: list[str],values: dict[str,Any]) -> None:
        table=self.sheets.setdefault(name,[])
        while len(table)<row_number:table.append([])
        row=list(table[row_number-1])+['']*max(0,len(headers)-len(table[row_number-1]))
        for header,value in values.items():
            if header in headers:row[headers.index(header)]=value
        table[row_number-1]=row[:len(headers)]

    def append(self, name: str, rows: list[list[Any]]) -> None:
        self.sheets.setdefault(name, [])
        self.sheets[name].extend(rows)

    def write_rows(self, name: str, start_row: int, rows: list[list[Any]], value_input: str = "RAW") -> None:
        del value_input
        table = self.sheets.setdefault(name, [])
        for offset, row in enumerate(rows):
            index = start_row - 1 + offset
            while len(table) <= index:
                table.append([])
            table[index] = list(row)

    def delete_rows(self, name: str, row_numbers: list[int]) -> int:
        table = self.sheets.get(name, [])
        deleted = 0
        for row_number in sorted(set(row_numbers), reverse=True):
            index = row_number - 1
            if 0 <= index < len(table):
                del table[index]
                deleted += 1
        return deleted


_credentials = None
_credential_lock = threading.Lock()
_http_session = None


def release_idle_memory() -> None:
    """Give freed heap pages back so an idle process does not keep a batch's footprint."""
    import gc

    gc.collect()
    try:
        import ctypes
        ctypes.CDLL("libc.so.6").malloc_trim(0)
    except Exception:
        return


def _access_token(info: dict[str, Any]) -> str:
    global _credentials
    with _credential_lock:
        if _credentials is None:
            from google.oauth2.service_account import Credentials

            _credentials = Credentials.from_service_account_info(info, scopes=[SHEETS_SCOPE])
        if not _credentials.valid:
            from google.auth.transport.requests import Request

            _credentials.refresh(Request())
        return _credentials.token


def _session():
    global _http_session
    if _http_session is None:
        import requests

        _http_session = requests.Session()
    return _http_session


class SheetsRest:
    """Sheets calls over HTTP. The discovery client stays out of the idle process."""

    def __init__(self, spreadsheet_id: str, info: dict[str, Any] | None = None, transport=None) -> None:
        self.spreadsheet_id = quote(spreadsheet_id, safe="")
        self._info = info or {}
        self._transport = transport

    def get_spreadsheet(self, fields: str) -> dict[str, Any]:
        return self._call("GET", f"/v4/spreadsheets/{self.spreadsheet_id}", params={"fields": fields})

    def batch_update(self, body: dict[str, Any]) -> dict[str, Any]:
        return self._call("POST", f"/v4/spreadsheets/{self.spreadsheet_id}:batchUpdate", body=body)

    def values_get(self, range_name: str) -> dict[str, Any]:
        return self._call("GET", f"/v4/spreadsheets/{self.spreadsheet_id}/values/{quote(range_name, safe='')}")

    def values_append(self, range_name: str, value_input: str, insert_data: str, body: dict[str, Any]) -> dict[str, Any]:
        return self._call(
            "POST",
            f"/v4/spreadsheets/{self.spreadsheet_id}/values/{quote(range_name, safe='')}:append",
            params={"valueInputOption": value_input, "insertDataOption": insert_data},
            body=body,
        )

    def values_update(self, range_name: str, value_input: str, body: dict[str, Any]) -> dict[str, Any]:
        return self._call(
            "PUT",
            f"/v4/spreadsheets/{self.spreadsheet_id}/values/{quote(range_name, safe='')}",
            params={"valueInputOption": value_input},
            body=body,
        )

    def values_batch_update(self, value_input: str, body: dict[str, Any]) -> dict[str, Any]:
        return self._call(
            "POST",
            f"/v4/spreadsheets/{self.spreadsheet_id}/values:batchUpdate",
            params={"valueInputOption": value_input},
            body=body,
        )

    def _call(self, method: str, path: str, params: dict[str, str] | None = None, body: dict[str, Any] | None = None) -> dict[str, Any]:
        if self._transport is not None:
            result = self._transport(method, path, params, body)
            return result if result is not None else {}
        delay = 2.0
        detail = ""
        for attempt in range(6):
            response = _session().request(
                method,
                "https://sheets.googleapis.com" + path,
                params=params,
                json=body,
                headers={"Authorization": f"Bearer {_access_token(self._info)}"},
                timeout=60,
            )
            try:
                if response.status_code in (429, 500, 502, 503):
                    detail = response.text[:500]
                    if attempt == 5:
                        break
                    time.sleep(_retry_wait(response, delay))
                    delay = min(delay * 2, 60)
                    continue
                if response.status_code >= 400:
                    detail = response.text[:500]
                    raise RuntimeError(f"Sheets API {response.status_code}: {detail}")
                if not response.content:
                    return {}
                parsed = response.json()
                return parsed if isinstance(parsed, dict) else {}
            finally:
                response.close()
        raise RuntimeError(f"Sheets API 429: {detail}")


def _retry_wait(response: Any, delay: float) -> float:
    retry_after = response.headers.get("Retry-After") if response is not None else None
    if retry_after:
        try:
            return min(max(float(retry_after), delay), 60)
        except (TypeError, ValueError):
            return min(delay, 60)
    return min(delay, 60)


def _copy_table(rows: list[list[Any]]) -> list[list[Any]]:
    return [list(row) for row in rows]


class GoogleSheetStore:
    def __init__(self, spreadsheet_id: str, info: dict[str, Any], http: SheetsRest | None = None) -> None:
        self.spreadsheet_id = spreadsheet_id
        self._info = info
        self._http = http
        self._sheet_ids: dict[str, int] = {}
        self._grids: dict[str, dict[str, int]] = {}
        self._values: dict[str, list[list[Any]]] = {}
        self._locale: str | None = None

    def _api(self) -> SheetsRest:
        if self._http is None:
            self._http = SheetsRest(self.spreadsheet_id, self._info)
        return self._http

    def _refresh_sheet_ids(self) -> None:
        meta = self._api().get_spreadsheet("sheets.properties")
        self._sheet_ids = {}
        self._grids = {}
        for sheet in meta.get("sheets", []):
            props = sheet.get("properties") or {}
            title = props.get("title")
            if not title:
                continue
            grid = props.get("gridProperties") or {}
            self._sheet_ids[title] = props["sheetId"]
            self._grids[title] = {
                "rowCount": int(grid.get("rowCount") or 0),
                "columnCount": int(grid.get("columnCount") or 0),
            }

    def ensure(self, name: str, headers: list[str]) -> None:
        if not self._sheet_ids:
            self._refresh_sheet_ids()
        if name not in self._sheet_ids:
            self._api().batch_update({"requests": [{"addSheet": {"properties": {"title": name}}}]})
            self._refresh_sheet_ids()
        existing = self.read(name)
        if not existing:
            self.write_rows(name, 1, [headers])
            return
        extended, changed = extend_header_row(existing[0], headers)
        if changed:
            self.write_rows(name, 1, [extended])

    def read(self, name: str) -> list[list[Any]]:
        cached = self._values.get(name)
        if cached is not None:
            return _copy_table(cached)
        result = self._api().values_get(_a1(name))
        table = _copy_table(result.get("values") or [])
        self._values[name] = table
        return _copy_table(table)

    def read_fresh(self, name: str) -> list[list[Any]]:
        self._values.pop(name,None)
        return self.read(name)

    def write_cells(self,name: str,row_number: int,headers: list[str],values: dict[str,Any]) -> None:
        if not values:return
        data=[]
        for header,value in values.items():
            if header not in headers:continue
            column=headers.index(header)+1
            cell=f"{_quote(name)}!{_column(column)}{row_number}:{_column(column)}{row_number}"
            data.append({'range':cell,'values':[[value]]})
        if not data:return
        self._api().values_batch_update('RAW',{'data':data})
        cached=self._values.get(name)
        if cached is not None:
            while len(cached)<row_number:cached.append([])
            row=cached[row_number-1]
            if len(row)<len(headers):row.extend(['']*(len(headers)-len(row)))
            for header,value in values.items():
                if header in headers:row[headers.index(header)]=value

    def append(self, name: str, rows: list[list[Any]]) -> None:
        if not rows:
            return
        width = max(len(row) for row in rows)
        self._api().values_append(
            f"{_quote(name)}!A1:{_column(width)}1",
            "RAW",
            "INSERT_ROWS",
            {"values": rows},
        )
        cached = self._values.get(name)
        if cached is not None:
            cached.extend(_copy_table(rows))

    def write_rows(self, name: str, start_row: int, rows: list[list[Any]], value_input: str = "RAW") -> None:
        if not rows:
            return
        width = max(len(row) for row in rows)
        end = start_row + len(rows) - 1
        self._ensure_grid(name, end, width)
        self._api().values_update(
            f"{_quote(name)}!A{start_row}:{_column(width)}{end}",
            value_input,
            {"values": rows},
        )
        cached = self._values.get(name)
        if cached is None:
            return
        for offset, row in enumerate(rows):
            index = start_row - 1 + offset
            while len(cached) <= index:
                cached.append([])
            cached[index] = list(row)

    def delete_rows(self, name: str, row_numbers: list[int]) -> int:
        if not row_numbers:
            return 0
        if name not in self._sheet_ids:
            self._refresh_sheet_ids()
        sheet_id = self._sheet_ids.get(name)
        if sheet_id is None:
            return 0
        requests = []
        for row_number in sorted(set(row_numbers), reverse=True):
            requests.append({
                "deleteDimension": {
                    "range": {
                        "sheetId": sheet_id,
                        "dimension": "ROWS",
                        "startIndex": row_number - 1,
                        "endIndex": row_number,
                    }
                }
            })
        self._api().batch_update({"requests": requests})
        cached = self._values.get(name)
        if cached is not None:
            for row_number in sorted(set(row_numbers), reverse=True):
                index = row_number - 1
                if 0 <= index < len(cached):
                    del cached[index]
        return len(requests)

    def _ensure_grid(self, name: str, min_rows: int, min_cols: int) -> None:
        if name not in self._sheet_ids:
            self._refresh_sheet_ids()
        sheet_id = self._sheet_ids.get(name)
        grid = self._grids.get(name) or {"rowCount": 0, "columnCount": 0}
        if sheet_id is None:
            return
        requests = grid_expansion_requests(
            sheet_id,
            grid["rowCount"],
            grid["columnCount"],
            min_rows,
            min_cols,
        )
        if not requests:
            return
        self._api().batch_update({"requests": requests})
        for request in requests:
            growth = request["appendDimension"]
            if growth["dimension"] == "ROWS":
                grid["rowCount"] += growth["length"]
            else:
                grid["columnCount"] += growth["length"]
        self._grids[name] = grid

    def spreadsheet_locale(self) -> str:
        if self._locale is not None:
            return self._locale
        try:
            meta = self._api().get_spreadsheet("properties.locale")
            self._locale = str((meta.get("properties") or {}).get("locale") or "")
        except Exception:
            logger.exception("Could not read spreadsheet locale")
            self._locale = ""
        return self._locale


def grid_growth(row_count: int, column_count: int, min_rows: int, min_cols: int) -> tuple[int, int]:
    return max(0, min_rows - row_count), max(0, min_cols - column_count)


def grid_expansion_requests(
    sheet_id: int,
    row_count: int,
    column_count: int,
    min_rows: int,
    min_cols: int,
) -> list[dict[str, Any]]:
    extra_rows, extra_cols = grid_growth(row_count, column_count, min_rows, min_cols)
    requests: list[dict[str, Any]] = []
    if extra_rows:
        requests.append({
            "appendDimension": {
                "sheetId": sheet_id,
                "dimension": "ROWS",
                "length": extra_rows,
            }
        })
    if extra_cols:
        requests.append({
            "appendDimension": {
                "sheetId": sheet_id,
                "dimension": "COLUMNS",
                "length": extra_cols,
            }
        })
    return requests


def sheets_configured() -> bool:
    return bool(os.getenv("GOOGLE_SERVICE_ACCOUNT_JSON") and os.getenv("GOOGLE_SHEET_ID"))


def load_service_account_info() -> dict[str, Any]:
    raw = os.getenv("GOOGLE_SERVICE_ACCOUNT_JSON", "").strip()
    if not raw:
        raise ValueError("GOOGLE_SERVICE_ACCOUNT_JSON is empty")
    try:
        info = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValueError("GOOGLE_SERVICE_ACCOUNT_JSON is not valid JSON") from exc
    if not isinstance(info, dict) or info.get("type") != "service_account" or not info.get("private_key"):
        raise ValueError("GOOGLE_SERVICE_ACCOUNT_JSON is not a service account key")
    return info


def build_store() -> GoogleSheetStore:
    return GoogleSheetStore(os.environ["GOOGLE_SHEET_ID"], load_service_account_info())


def ensure_shop_sheet(store: MemoryStore | GoogleSheetStore) -> None:
    """Write the pricing curve. Column B keeps a number the operator already set."""
    store.ensure("Shop", ["Min Margin"])
    existing = _read_sheet_if_present(store, "Shop")
    locale = ""
    reader = getattr(store, "spreadsheet_locale", None)
    if callable(reader):
        try:
            locale = reader() or ""
        except Exception:
            logger.exception("Shop sheet locale was not available")
    store.write_rows("Shop", 1, shop_setting_rows(existing), "USER_ENTERED")
    store.write_rows("Shop", 10, [[
        "Edit column B. The shop and the cart read these cells. The rows below follow the same rules.",
    ]], "USER_ENTERED")
    store.write_rows("Shop", SHOP_HEADER_ROW, [SHOP_HEADERS], "USER_ENTERED")
    store.write_rows("Shop", SHOP_FORMULA_ROW, [shop_formulas(formula_separator(locale))], "USER_ENTERED")


def _shop_setting_rows(store: MemoryStore | GoogleSheetStore) -> list[list[Any]]:
    """Settings already on the Shop sheet. A sheet that does not exist yet uses defaults."""
    if isinstance(store, MemoryStore):
        if "Shop" not in store.sheets:
            return []
        return store.read("Shop")
    try:
        if not store._sheet_ids:
            store._refresh_sheet_ids()
        if "Shop" not in store._sheet_ids:
            return []
    except Exception:
        logger.exception("Could not list sheets for the shop")
        return []
    return _read_sheet_if_present(store, "Shop")


def shop_catalog(store: MemoryStore | GoogleSheetStore) -> dict[str, Any]:
    """Products the site can sell, priced from the Shop sheet settings."""
    settings = settings_from_rows(_shop_setting_rows(store))
    _headers, records = _records(_read_sheet_if_present(store, "Products"))
    return {
        "settings": settings.as_dict(),
        "products": catalog_from_records(records, settings),
    }


def finalize_batch(store: MemoryStore | GoogleSheetStore, payload: dict[str, Any], *, reference_snapshot=None) -> dict[str, Any]:
    with _WRITE_LOCK:
        return _finalize_batch(store, payload, reference_snapshot=reference_snapshot)


def _finalize_batch(store: MemoryStore | GoogleSheetStore, payload: dict[str, Any], *, reference_snapshot=None) -> dict[str, Any]:
    batch_id = str(payload.get("batch_id") or "")
    messages = payload.get("messages") if isinstance(payload.get("messages"), list) else []
    expected = int(payload.get("expected_count") or 0) or len(messages) or 0
    written = 0

    if messages:
        share_album_images(messages)
        store.ensure("MessageData", MESSAGE_HEADERS)
        headers = _headers(store, "MessageData", MESSAGE_HEADERS)
        rows = []
        current = store.read("MessageData") or []
        keys = ("ID", "Channel Username", "Content", "Image File ID")
        indices = [headers.index(k) for k in keys]
        batch_index = headers.index("Batch ID")
        existing = {tuple(str(row[i] or "") if i < len(row) else "" for i in indices)
                    for row in current[1:] if batch_index < len(row) and str(row[batch_index]) == batch_id}
        imported_at = _now()
        for message in messages:
            message = dict(message or {})
            message["batch_id"] = batch_id
            classification = classify_message(message.get("content") or "", message.get("channel_username") or message.get("channel") or "")
            row = _row_from_map(headers, {
                "ID": message.get("id") or "",
                "Channel": message.get("channel") or "",
                "Channel Username": message.get("channel_username") or "",
                "Author": message.get("author") or "",
                "Content": message.get("content") or "",
                "Timestamp": message.get("timestamp") or "",
                "URL": message.get("url") or "",
                "Forwarded By": message.get("forwarded_by") or "",
                "Forwarded At": message.get("forwarded_at") or "",
                "Has Media": bool(message.get("has_media") or False),
                "Media Type": message.get("media_type") or "",
                "Import Timestamp": imported_at,
                "Status": classification["type"] or "imported",
                "Batch ID": batch_id,
                "AI Status": "pending_ai",
                "AI Review Case IDs": "",
                "AI Processed At": "",
                "Image File ID": message.get("image_file_id") or "",
                "Media Group ID": message.get("media_group_id") or "",
            })
            key = tuple(str(row[i] or "") for i in indices)
            if key not in existing:
                rows.append(row)
                existing.add(key)
        if rows:
            _append_at_next_row(store, "MessageData", headers, rows)
        written = _count_batch_messages(store, batch_id)
    else:
        written = _count_batch_messages(store, batch_id)

    if expected > 0 and written != expected:
        extraction = {"status": "error", "processed": 0, "rollback": False}
        _record_batch_status(store, batch_id, expected, written, extraction)
        return {
            "status": "error",
            "ack": "ingestion_incomplete",
            "batch_id": batch_id,
            "expected_count": expected,
            "written_count": written,
        }

    extraction = _process_batch_products(store, batch_id, messages, reference_snapshot=reference_snapshot)
    _record_batch_status(store, batch_id, expected, written, extraction)
    desk = {}
    try:
        desk = refresh_buying_desk(store, messages, batch_id)
    except Exception:
        logger.exception("Buying desk refresh failed")
    try:
        ensure_shop_sheet(store)
    except Exception:
        logger.exception("Shop sheet refresh failed")
    return {
        "status": "success",
        "ack": "ingestion_complete",
        "batch_id": batch_id,
        "expected_count": expected,
        "written_count": written,
        "extraction_status": extraction["status"],
        "processed_messages": extraction["processed"],
        "rollback": bool(extraction.get("rollback")),
        "saved": desk.get("saved") or [],
        "prompts": desk.get("prompts") or [],
    }


def _process_batch_products(store, batch_id: str, messages: list[dict[str, Any]], *, reference_snapshot=None) -> dict[str, Any]:
    processed = 0
    items = messages or [{"id": message_id, "batch_id": batch_id} for message_id in _list_batch_message_ids(store, batch_id)]
    for message in items:
        message = dict(message or {})
        message["batch_id"] = batch_id
        ok = False
        prepared_products = None
        if ai_extraction_enabled():
            try:
                _fill_message_from_sheet(store, message)
                prepared_products = _extract_supplier_products(
                    message.get("content") or "", message.get("channel_username") or "")
            except Exception as exc:
                logger.warning("Could not precompute supplier extraction: %s", exc.__class__.__name__)
        for _attempt in range(3):
            result = import_product_data(
                store, message, reference_snapshot=reference_snapshot,
                prepared_products=prepared_products)
            ok = bool(result and result.get("success"))
            if ok:
                break
        if not ok:
            _rollback_catalog(store, batch_id)
            return {"status": "error", "processed": processed, "rollback": True}
        try:
            refresh_ai_message_status(store, message)
        except Exception:
            logger.exception('Could not persist AI message status')
        processed += 1
    return {"status": "success", "processed": processed}


def _ai_review_reason(offer):
    if str(offer.get('Status') or '').strip().lower() != 'needs_review':
        return False
    reasons=[part.strip().lower() for part in str(offer.get('Review Reason') or '').split(';') if part.strip()]
    return any(not reason.startswith('reference:') for reason in reasons)


def _write_ai_message_status(store, message, status, case_ids):
    store.ensure('MessageData', MESSAGE_HEADERS)
    rows=_fresh_read(store,'MessageData')
    if not rows:
        return False
    headers=[str(header) for header in rows[0]]
    required=('ID','Batch ID','AI Status','AI Review Case IDs','AI Processed At')
    if any(header not in headers for header in required):
        return False
    message_id=str(message.get('id') or '')
    batch_id=str(message.get('batch_id') or '')
    channel=str(message.get('channel') or '')
    id_idx=headers.index('ID');batch_idx=headers.index('Batch ID')
    channel_idx=headers.index('Channel') if 'Channel' in headers else None
    rows_found=[]
    for row_index,row in enumerate(rows[1:],2):
        if str(row[id_idx] if id_idx<len(row) else '')!=message_id:
            continue
        if batch_id and str(row[batch_idx] if batch_idx<len(row) else '')!=batch_id:
            continue
        if channel_idx is not None and channel and str(row[channel_idx] if channel_idx<len(row) else '')!=channel:
            continue
        rows_found.append(row_index)
    values={'AI Status':status,'AI Review Case IDs':json.dumps(case_ids,ensure_ascii=False),
            'AI Processed At':_now()}
    writer=getattr(store,'write_cells',None)
    if callable(writer):
        for row_index in rows_found:
            writer('MessageData',row_index,headers,values)
    else:
        for row_index in rows_found:
            row=list(rows[row_index-1])+['']*max(0,len(headers)-len(rows[row_index-1]))
            for field,value in values.items():row[headers.index(field)]=value
            store.write_rows('MessageData',row_index,[row[:len(headers)]])
    return bool(rows_found)


def refresh_ai_message_status(store, message):
    """Mirror the durable offer-review lifecycle onto its source MessageData row."""
    from catalog.ai_confirmations import review_case_id
    message=dict(message or {})
    message_id=str(message.get('id') or '')
    batch_id=str(message.get('batch_id') or '')
    if not message_id:
        return False
    offers_raw=_fresh_read(store,'Offers')
    _,offers=_records(offers_raw)
    message_offers=[offer for offer in offers if str(offer.get('Message ID') or '')==message_id]
    source_ids={str(offer.get('Offer ID') or '') for offer in message_offers
        if not batch_id or str(offer.get('Batch ID') or '')==batch_id}
    matching=[offer for offer in message_offers if (not batch_id
        or str(offer.get('Batch ID') or '')==batch_id
        or str(offer.get('Derived From Offer ID') or '') in source_ids)]
    latest={}
    for offer in matching:
        latest[(str(offer.get('Product Name') or ''),str(offer.get('Channel Username') or ''))]=offer
    current=list(latest.values())
    pending=[offer for offer in current if _ai_review_reason(offer)]
    case_ids=[review_case_id(str(offer.get('Offer ID') or '')) for offer in pending if offer.get('Offer ID')]
    if case_ids:
        status='needs_confirmation'
    elif current and all(str(offer.get('Status') or '').lower()=='not_product' for offer in current):
        status='not_product'
    elif current:
        status='ai_processed'
    else:
        status='not_product'
    message.setdefault('batch_id',batch_id)
    return _write_ai_message_status(store,message,status,case_ids)


def _extract_supplier_products(content, channel):
    if ai_extraction_enabled():
        try:
            products = extract_message_products(content, channel)
            if products:
                return products
        except GeminiError as exc:
            logger.warning("Gemini extraction failed; using regex fallback: %s", exc)
    return extract_products(content, channel)


def import_product_data(
    store,
    data: dict[str, Any],
    *,
    reference_snapshot=None,
    prepared_products: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    try:
        _fill_message_from_sheet(store, data)
        content = data.get("content") or ""
        channel = data.get("channel_username") or ""
        products = (
            [dict(product) for product in prepared_products]
            if prepared_products is not None
            else _extract_supplier_products(content, channel)
        )
        if not products:
            return {"success": True, "products_found": 0, "row": None}

        reference_headers = []
        store.ensure("Offers", OFFER_HEADERS + reference_headers)
        store.ensure("Products", PRODUCT_HEADERS)
        offer_headers = _headers(store, "Offers", OFFER_HEADERS + reference_headers)
        existing_offers = _read_sheet_if_present(store, "Offers")
        _, existing_offer_records = _records(existing_offers)
        imported_at = _now()
        offer_rows = []
        keys: list[tuple[str, str]] = []
        for index, product in enumerate(products):
            product["min_qty"] = minimum_purchase(product.get("packaging") or "", product.get("name") or "")
            if 'detected_price' in product or 'source_price_amount' in product:
                from catalog.price_scale import apply_price_scale, assess_price_scale
                scale = assess_price_scale(product, data, existing_offer_records,
                    (reference_snapshot or {}).get('rows') or [], min_history=1)
                apply_price_scale(product, scale)
            qa = quality_check(
                {
                    "name": product.get("name"),
                    "sale_price": product.get("sale_price") or product.get("price") or 0,
                    "actual_price": product.get("actual_price") or product.get("consumer_price") or 0,
                    "extraction_confidence": product.get("extraction_confidence") or product.get("confidence") or 0,
                },
                existing_offers,
                offer_headers,
            )
            review_reasons = [str(qa.get("reason") or "").strip()]
            if product.get("needs_review"):
                review_reasons.append(str(product.get("review_reason") or "AI extraction requires review").strip())
            review_reason = "; ".join(dict.fromkeys(reason for reason in review_reasons if reason))
            requires_review = bool(qa["requires_review"] or product.get("needs_review"))
            product["status"] = "needs_review" if requires_review else "imported"
            if requires_review:
                _log_issue(store, "WARN", data.get("id"), review_reason)
            offer_id = f"{data.get('batch_id') or 'offer'}:{data.get('id') or index}:{index}"
            fields = _offer_fields(product, data, offer_id, imported_at, review_reason)
            if reference_snapshot is not None:
                from reference_import.matching import candidate_match, price_role_hint
                match = candidate_match(product, reference_snapshot["rows"],
                                        overrides=reference_snapshot.get('item_overrides'))
                fields["Reference Snapshot ID"] = reference_snapshot["snapshot_id"]
                fields["Reference Usage Warning"] = reference_snapshot.get("usage_warning") or ""
                fields["Reference Match State"] = match["reason"]
                reference_review_reason = ''
                if match["state"] == "candidate":
                    candidate = match["candidates"][0]
                    fields["Reference Match ID"] = candidate["reference_id"]
                    role_hint = candidate.get('owner_price_role') or price_role_hint(product.get("price"), candidate)["role"]
                    fields["Reference Price Role Hint"] = role_hint
                    fields["Reference Decision ID"] = candidate.get('owner_decision_id') or ''
                    if role_hint not in {'unit', 'package'}:
                        reference_review_reason = 'reference: price_role_needs_review'
                else:
                    fields["Reference Price Role Hint"] = "needs_review"
                    reference_review_reason = "reference: " + match["reason"]
                if reference_review_reason:
                    fields["Status"] = "needs_review"
                    existing_reason = str(fields.get('Review Reason') or '').strip()
                    fields["Review Reason"] = '; '.join(part for part in (existing_reason, reference_review_reason) if part)
            offer_rows.append(_row_from_map(offer_headers, fields))
            keys.append((product.get("name") or "", data.get("channel_username") or ""))

        opening_rows = _opening_offer_rows(store, keys, offer_headers, existing_offers)
        fresh_rows = _unseen_offer_rows(existing_offers, offer_headers, opening_rows + offer_rows)
        if fresh_rows:
            _append_at_next_row(store, "Offers", offer_headers, fresh_rows)
        last_row = _restore_current_book(store, _dedupe_keys(keys))
        _check_systemic_errors(store)
        return {"success": True, "products_found": len(products), "row": last_row}
    except Exception as exc:
        logger.error("Product import failed: %s", exc.__class__.__name__)
        return {"success": False, "error": exc.__class__.__name__}


_SYSTEM_ITEM_FIELDS = {
    "Item Key",
    "Channel Username",
    "Supplier Product Name",
    "Status",
    "Last Sale Price",
    "Last Consumer Price",
    "Last Offer ID",
    "Updated At",
}

_DECISION_REASON_ORDER = (
    "missing unit price",
    "unusual spread",
    "low confidence",
)
_PROMPT_SETTLED = {"accepted", "corrected", "skipped"}
_ACCEPT_ANSWERS = {"ok", "okay", "yes", "y", "باشه", "بله", "درسته", "درست", "تایید", "تأیید", "قبول"}
_SKIP_ANSWERS = {"skip", "no", "n", "رد", "نه", "بیخیال", "لغو"}


def refresh_buying_desk(
    store: MemoryStore | GoogleSheetStore,
    messages: list[dict[str, Any]] | None = None,
    batch_id: str = "",
) -> dict[str, Any]:
    """Rebuild the optional item rows, the doubt list, and bot prompts.

    A clear price is not a prompt. Confirmed Item Name and Our Sell Price
    stay as a person typed them.
    """
    products = _read_sheet_if_present(store, "Products")
    store.ensure("Items", ITEM_HEADERS)
    item_headers = _headers(store, "Items", ITEM_HEADERS)
    existing_items = _records(_read_sheet_if_present(store, "Items"))[1]
    items_by_key = {
        str(item.get("Item Key") or ""): item
        for item in existing_items
        if item.get("Item Key")
    }
    items_by_product = {
        _item_key(item.get("Channel Username") or "", item.get("Supplier Product Name") or ""): item
        for item in existing_items
        if item.get("Supplier Product Name")
    }
    _, product_rows = _records(products)
    _, offer_rows = _records(_read_sheet_if_present(store, "Offers"))
    review_by_offer = {
        str(offer.get("Offer ID") or ""): str(offer.get("Review Reason") or "")
        for offer in offer_rows
    }
    seen: set[str] = set()
    item_rows: list[list[Any]] = []
    desk_rows: list[dict[str, Any]] = []
    updated_at = _now()
    for product in product_rows:
        name = str(product.get("Product Name") or "").strip()
        channel = str(product.get("Channel Username") or "").strip()
        if not name:
            continue
        key = _item_key(channel, name)
        seen.add(key)
        existing = items_by_key.get(key) or items_by_product.get(key) or {}
        if existing.get("Item Key"):
            seen.add(str(existing.get("Item Key")))
        confirmed = str(existing.get("Confirmed Item Name") or "").strip()
        consumer = product.get("Consumer Price")
        if _positive_price(consumer) is None:
            consumer = product.get("Actual Price")
        updates = {
            "Item Key": key,
            "Channel Username": channel,
            "Supplier Product Name": name,
            "Status": "confirmed" if confirmed else "unconfirmed",
            "Last Sale Price": product.get("Sale Price") if product.get("Sale Price") not in (None, "") else "",
            "Last Consumer Price": consumer if consumer not in (None, "") else "",
            "Last Offer ID": product.get("Latest Offer ID") or "",
            "Updated At": updated_at,
        }
        item_rows.append(_merged_item_row(item_headers, existing, updates))
        desk_rows.append({
            "key": key,
            "channel": channel,
            "name": name,
            "confirmed": confirmed,
            "sale": product.get("Sale Price"),
            "previous": product.get("Previous Sale Price"),
            "consumer": consumer,
            "our_sell": existing.get("Our Sell Price") if existing.get("Our Sell Price") not in (None, "") else "",
            "offer_id": str(product.get("Latest Offer ID") or ""),
            "status": str(product.get("Status") or ""),
            "review_reason": review_by_offer.get(str(product.get("Latest Offer ID") or ""), ""),
        })
    for key, existing in items_by_key.items():
        if key in seen:
            continue
        item_rows.append(_merged_item_row(item_headers, existing, {}))
    _replace_sheet_rows(store, "Items", item_headers, item_rows)

    settled_offers = _settled_offer_ids(store)
    for desk in desk_rows:
        if desk["offer_id"] in settled_offers or desk["offer_id"].startswith("operator:"):
            desk["reasons"] = []
        else:
            desk["reasons"] = _decision_reasons(desk)
    store.ensure("Decisions", DECISION_HEADERS)
    decision_headers = _headers(store, "Decisions", DECISION_HEADERS)
    previous_decisions = {
        str(row.get("Item Key") or ""): row
        for row in _records(_read_sheet_if_present(store, "Decisions"))[1]
        if row.get("Item Key")
    }
    decision_rows = []
    for desk in desk_rows:
        reasons = desk.get("reasons") or []
        if not reasons:
            continue
        decision_rows.append(_decision_row(decision_headers, desk, reasons, previous_decisions.get(desk["key"])))
    _replace_sheet_rows(store, "Decisions", decision_headers, decision_rows)
    notice = _sync_prompts(store, desk_rows, messages or [], batch_id)
    return {
        "items": len(item_rows),
        "decisions": len(decision_rows),
        "saved": notice["saved"],
        "prompts": notice["prompts"],
    }


def _product_key(name: str) -> str:
    """Identity of a product after a trailing carton count is removed."""
    peeled, _pack = peel_title_pack(name or "")
    return re.sub(r"[\s،,:：\-]+$", "", peeled).strip().lower()


def _item_key(channel: str, name: str) -> str:
    return f"{(channel or '').strip()}|{_product_key(name)}"


def parse_prompt_answer(text: str) -> tuple[str, int | None] | None:
    """Read a bot reply: accept, skip, or a unit price."""
    cleaned = normalize(text or "").strip().lower()
    if cleaned in _ACCEPT_ANSWERS:
        return ("accept", None)
    if cleaned in _SKIP_ANSWERS:
        return ("skip", None)
    without_currency = re.sub(r"(تومان|تومن|ریال|\bت\b|toman|irr|irt)", " ", cleaned, flags=re.I).strip()
    if re.fullmatch(r"[\d\s,./٫\u066B]+", without_currency):
        price = parse_price(without_currency)
        if price > 0:
            return ("correct", price)
    return None


def resolve_prompt(store: MemoryStore | GoogleSheetStore, chat_id: Any, text: str) -> dict[str, Any]:
    """Apply a reply to the open question for this chat."""
    records = _prompt_records(store)
    waiting = _waiting_prompt(records, chat_id)
    if not waiting:
        return {"matched": False}
    parsed = parse_prompt_answer(text)
    if not parsed:
        return {
            "matched": True,
            "understood": False,
            "reply": "قیمت واحد را بفرست، یا «باشه» برای تأیید، یا «رد».\n\n" + str(waiting.get("Question") or ""),
        }
    action, price = parsed
    if action == "correct":
        _apply_operator_price(store, waiting, price or 0)
        waiting["Status"] = "corrected"
        waiting["Answer"] = str(price)
        reply = f"قیمت واحد {price} برای {waiting.get('Product Name') or 'این مورد'} ثبت شد."
    elif action == "skip":
        _mark_product_status(store, waiting, "skipped")
        waiting["Status"] = "skipped"
        waiting["Answer"] = "skip"
        reply = f"{waiting.get('Product Name') or 'این مورد'} رد شد."
    else:
        _mark_product_status(store, waiting, "imported")
        waiting["Status"] = "accepted"
        waiting["Answer"] = "ok"
        reply = f"{waiting.get('Product Name') or 'این مورد'} با همین قیمت‌ها ثبت شد."
    _write_prompt_records(store, records)
    desk = refresh_buying_desk(store)
    follow = desk.get("prompts") or []
    if follow:
        reply = reply + "\n\n" + str(follow[0].get("question") or "")
    return {"matched": True, "understood": True, "action": action, "reply": reply}


def _sync_prompts(store, desk_rows: list[dict[str, Any]], messages: list[dict[str, Any]], batch_id: str = "") -> dict[str, list]:
    records = _prompt_records(store)
    known_offers = {str(row.get("Offer ID") or "") for row in records if row.get("Offer ID")}
    chats = _chats_by_message(messages)
    for desk in desk_rows:
        offer_id = str(desk.get("offer_id") or "")
        reasons = desk.get("reasons") or []
        if not reasons or not offer_id or offer_id.startswith("operator:") or offer_id in known_offers:
            continue
        chat_id = _chat_for_offer(offer_id, chats)
        record = {
            "Prompt ID": f"p:{offer_id}",
            "Chat ID": chat_id,
            "Item Key": desk["key"],
            "Offer ID": offer_id,
            "Channel Username": desk["channel"],
            "Product Name": desk["name"],
            "Reason": "; ".join(reasons),
            "Question": _prompt_question(desk, reasons),
            "Status": "open",
            "Answer": "",
            "Created At": _now(),
        }
        records.append(record)
        known_offers.add(offer_id)
    to_send = _mark_next_prompts_asked(records)
    _write_prompt_records(store, records)
    saved = []
    for desk in desk_rows:
        offer_id = str(desk.get("offer_id") or "")
        if desk.get("reasons") or not batch_id or not offer_id.startswith(f"{batch_id}:"):
            continue
        saved.append({
            "name": desk["name"],
            "channel": desk["channel"],
            "sale": _positive_price(desk.get("sale")) or "",
            "consumer": _positive_price(desk.get("consumer")) or "",
        })
    return {"saved": saved, "prompts": [_public_prompt(row) for row in to_send]}


def _prompt_question(desk: dict[str, Any], reasons: list[str]) -> str:
    sale = _positive_price(desk.get("sale"))
    consumer = _positive_price(desk.get("consumer"))
    return (
        "این مورد را مطمئن نیستم.\n"
        f"{desk['name']}\n"
        f"{desk['channel']}\n"
        f"قیمت واحد: {sale if sale else 'پیدا نشد'}\n"
        f"قیمت مصرف: {consumer if consumer else 'پیدا نشد'}\n"
        f"علت: {_persian_reasons(reasons)}\n\n"
        "قیمت واحد را بفرست، یا «باشه» برای تأیید، یا «رد»."
    )


def _persian_reasons(reasons: list[str]) -> str:
    labels = []
    for reason in reasons:
        if reason == "missing unit price":
            labels.append("قیمت واحد پیدا نشد")
        elif reason == "unusual spread" or reason.startswith("Unusual discount"):
            labels.append("فاصله قیمت واحد و مصرف غیرعادی است")
        elif reason == "low confidence" or "low confidence" in reason:
            labels.append("استخراج نامطمئن بود")
        elif "deviates from history" in reason:
            labels.append("با قیمت‌های قبلی خیلی فرق دارد")
        else:
            labels.append(reason)
    return "، ".join(labels)


def _prompt_records(store) -> list[dict[str, Any]]:
    store.ensure("Prompts", PROMPT_HEADERS)
    return _records(_read_sheet_if_present(store, "Prompts"))[1]


def _write_prompt_records(store, records: list[dict[str, Any]]) -> None:
    headers = _headers(store, "Prompts", PROMPT_HEADERS)
    _replace_sheet_rows(store, "Prompts", headers, [_row_from_map(headers, record) for record in records])


def _settled_offer_ids(store) -> set[str]:
    if isinstance(store, MemoryStore) and "Prompts" not in store.sheets:
        return set()
    try:
        records = _records(_read_sheet_if_present(store, "Prompts"))[1]
    except Exception:
        return set()
    return {
        str(row.get("Offer ID") or "")
        for row in records
        if row.get("Offer ID") and str(row.get("Status") or "") in _PROMPT_SETTLED
    }


def _waiting_prompt(records: list[dict[str, Any]], chat_id: Any) -> dict[str, Any] | None:
    chat = str(chat_id or "")
    same = [row for row in records if str(row.get("Chat ID") or "") == chat]
    for status in ("asked", "open"):
        for row in same:
            if row.get("Status") == status:
                return row
    return None


def _mark_next_prompts_asked(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    busy = {str(row.get("Chat ID") or "") for row in records if row.get("Status") == "asked" and row.get("Chat ID")}
    asked = []
    for row in records:
        if row.get("Status") != "open":
            continue
        chat = str(row.get("Chat ID") or "")
        if not chat or chat in busy:
            continue
        row["Status"] = "asked"
        busy.add(chat)
        asked.append(row)
    return asked


def _chats_by_message(messages: list[dict[str, Any]]) -> dict[str, str]:
    found = {}
    for message in messages:
        message_id = str(message.get("id") or "")
        chat_id = str(message.get("chat_id") or "")
        if message_id and chat_id:
            found[message_id] = chat_id
    return found


def _chat_for_offer(offer_id: str, chats: dict[str, str]) -> str:
    parts = str(offer_id or "").split(":")
    if len(parts) >= 3 and parts[1] in chats:
        return chats[parts[1]]
    unique = {chat for chat in chats.values() if chat}
    if len(unique) == 1:
        return unique.pop()
    return ""


def _public_prompt(row: dict[str, Any]) -> dict[str, str]:
    return {
        "prompt_id": str(row.get("Prompt ID") or ""),
        "chat_id": str(row.get("Chat ID") or ""),
        "question": str(row.get("Question") or ""),
        "name": str(row.get("Product Name") or ""),
        "reason": str(row.get("Reason") or ""),
    }


def _apply_operator_price(store, prompt: dict[str, Any], sale_price: int) -> None:
    consumer = _product_consumer(store, prompt)
    offer_id = f"operator:{prompt.get('Prompt ID') or sale_price}"
    imported_at = _now()
    message = {
        "channel_username": prompt.get("Channel Username") or "",
        "channel": "telegram",
        "content": prompt.get("Product Name") or "",
        "id": prompt.get("Prompt ID") or "",
        "batch_id": "operator",
        "timestamp": imported_at,
        "forwarded_by": "operator",
    }
    product = {
        "name": prompt.get("Product Name") or "",
        "sale_price": sale_price,
        "price": sale_price,
        "consumer_price": consumer,
        "actual_price": consumer,
        "currency": "IRT",
        "status": "imported",
        "confidence": 1,
        "extraction_confidence": 1,
        "stock_status": "Available",
    }
    store.ensure("Offers", OFFER_HEADERS)
    headers = _headers(store, "Offers", OFFER_HEADERS)
    _append_at_next_row(store, "Offers", headers, [_row_from_map(headers, _offer_fields(product, message, offer_id, imported_at, ""))])
    _restore_current_book(store, [(product["name"], message["channel_username"])])


def _product_consumer(store, prompt: dict[str, Any]) -> Any:
    products = _read_sheet_if_present(store, "Products")
    headers, rows = _records(products)
    if not rows and products:
        headers = [str(header) for header in products[0]]
    for row in rows:
        if str(row.get("Product Name") or "") == str(prompt.get("Product Name") or "") and str(row.get("Channel Username") or "") == str(prompt.get("Channel Username") or ""):
            consumer = _positive_price(row.get("Consumer Price")) or _positive_price(row.get("Actual Price"))
            return consumer if consumer is not None else ""
    return ""


def _mark_product_status(store, prompt: dict[str, Any], status: str) -> None:
    products = _read_sheet_if_present(store, "Products")
    if len(products) < 2 or "Status" not in products[0]:
        return
    headers = [str(header) for header in products[0]]
    name_index = headers.index("Product Name") if "Product Name" in headers else None
    channel_index = headers.index("Channel Username") if "Channel Username" in headers else None
    status_index = headers.index("Status")
    if name_index is None or channel_index is None:
        return
    for row_number, row in enumerate(products[1:], start=2):
        name = row[name_index] if name_index < len(row) else ""
        channel = row[channel_index] if channel_index < len(row) else ""
        if str(name) == str(prompt.get("Product Name") or "") and str(channel) == str(prompt.get("Channel Username") or ""):
            padded = list(row) + [""] * max(0, len(headers) - len(row))
            padded[status_index] = status
            store.write_rows("Products", row_number, [padded[:len(headers)]])
            return


def _decision_reasons(desk: dict[str, Any]) -> list[str]:
    if str(desk.get("status") or "") == "skipped":
        return []
    reasons = []
    sale = _positive_price(desk.get("sale"))
    consumer = _positive_price(desk.get("consumer"))
    if sale is None:
        reasons.append("missing unit price")
    if _unusual_spread(sale, consumer):
        reasons.append("unusual spread")
    if str(desk.get("status") or "") == "needs_review":
        for part in str(desk.get("review_reason") or "").split(";"):
            part = part.strip()
            if not part or part.startswith("Unusual discount"):
                continue
            if part not in reasons:
                reasons.append(part)
        if not reasons:
            reasons.append("low confidence")
    return _sorted_reasons(reasons)


def _sorted_reasons(reasons: list[str]) -> list[str]:
    order = {reason: index for index, reason in enumerate(_DECISION_REASON_ORDER)}

    def rank(reason: str) -> tuple[int, str]:
        if reason in order:
            return order[reason], reason
        return len(order), reason

    return sorted(reasons, key=rank)


def _unusual_spread(sale: int | None, consumer: int | None) -> bool:
    if sale is None or consumer is None:
        return False
    discount = 1 - (sale / consumer)
    return discount > 0.8 or discount < 0.05


def _decision_row(headers: list[str], desk: dict[str, Any], reasons: list[str], previous: dict[str, Any] | None) -> list[Any]:
    sale = _positive_price(desk.get("sale"))
    consumer = _positive_price(desk.get("consumer"))
    our_sell = _positive_price(desk.get("our_sell"))
    compared = next((reason for reason in reasons if reason.startswith("higher than ")), "")
    visible = [reason for reason in reasons if reason != compared]
    fields = {
        "Item Key": desk["key"],
        "Channel Username": desk["channel"],
        "Supplier Product Name": desk["name"],
        "Confirmed Item Name": desk.get("confirmed") or "",
        "Reasons": "; ".join(visible) if visible else compared,
        "Sale Price": sale if sale is not None else "",
        "Previous Sale Price": _positive_price(desk.get("previous")) or "",
        "Consumer Price": consumer if consumer is not None else "",
        "Our Sell Price": our_sell if our_sell is not None else "",
        "Spread": our_sell - sale if our_sell is not None and sale is not None else "",
        "Shelf Gap": consumer - sale if consumer is not None and sale is not None else "",
        "Compared With": compared,
        "Latest Offer ID": desk.get("offer_id") or "",
    }
    row: list[Any] = [""] * len(headers)
    for index, header in enumerate(headers):
        if header in fields and fields[header] is not None:
            row[index] = fields[header]
        elif previous and header not in DECISION_HEADERS and header in previous and previous[header] not in (None, ""):
            row[index] = previous[header]
    return row


def _merged_item_row(headers: list[str], existing: dict[str, Any], updates: dict[str, Any]) -> list[Any]:
    row: list[Any] = [""] * len(headers)
    for index, header in enumerate(headers):
        if existing and header in existing and existing[header] not in (None,):
            row[index] = existing[header]
        if header in _SYSTEM_ITEM_FIELDS and header in updates:
            row[index] = "" if updates[header] is None else updates[header]
        elif not existing and header in updates:
            row[index] = "" if updates[header] is None else updates[header]
    return row


def _replace_sheet_rows(store, name: str, headers: list[str], rows: list[list[Any]]) -> None:
    store.ensure(name, headers)
    current = _read_sheet_if_present(store, name)
    sheet_headers = _headers(store, name, headers)
    width = len(sheet_headers)
    padded = [list(row) + [""] * max(0, width - len(row)) for row in rows]
    padded = [row[:width] for row in padded]
    store.write_rows(name, 1, [sheet_headers] + padded)
    extra = list(range(len(padded) + 2, len(current) + 1))
    if extra:
        store.delete_rows(name, extra)


def _fill_message_from_sheet(store, data: dict[str, Any]) -> None:
    if data.get("channel_username") and data.get("channel") and data.get("content"):
        return
    rows = store.read("MessageData")
    if len(rows) < 2:
        return
    headers = rows[0]
    indexes = {header: index for index, header in enumerate(headers)}
    id_idx = indexes.get("ID")
    if id_idx is None:
        return
    for row in rows[1:]:
        if id_idx < len(row) and str(row[id_idx]) == str(data.get("id")):
            if not data.get("channel") and "Channel" in indexes and indexes["Channel"] < len(row):
                data["channel"] = row[indexes["Channel"]] or ""
            if not data.get("channel_username") and "Channel Username" in indexes and indexes["Channel Username"] < len(row):
                data["channel_username"] = row[indexes["Channel Username"]] or ""
            if not data.get("content") and "Content" in indexes and indexes["Content"] < len(row):
                data["content"] = row[indexes["Content"]] or ""
            return


def _find_product_row(rows: list[list[Any]], headers: list[str], product_name: str, channel_username: str) -> int | None:
    try:
        name_idx = headers.index("Product Name")
        channel_idx = headers.index("Channel Username")
    except ValueError:
        return None
    target = _product_key(product_name)
    for index, row in enumerate(rows[1:], start=2):
        existing_name = row[name_idx] if name_idx < len(row) else ""
        existing_channel = row[channel_idx] if channel_idx < len(row) else ""
        if existing_name and _product_key(str(existing_name)) == target and existing_channel == channel_username:
            return index
    return None


def _product_row(headers: list[str], product: dict[str, Any], message: dict[str, Any]) -> list[Any]:
    now = _now()
    return _row_from_map(headers, {
        "Channel ID": message.get("channel_username") or message.get("channel") or "",
        "Product ID": _product_id(product.get("name") or ""),
        "Product Name": product.get("name") or "",
        "Variation Type": product.get("variation_type") or "",
        "Sale Price": product.get("sale_price"),
        "Actual Price": product.get("actual_price"),
        "Price Type": product.get("price_type") or "",
        "Price": product.get("price"),
        "Currency": product.get("currency") or "",
        "Consumer Price": product.get("consumer_price"),
        "Double Pack Price": product.get("double_pack_price"),
        "Double Pack Consumer Price": product.get("double_pack_consumer_price"),
        "Packaging": product.get("packaging") or "",
        "Volume": product.get("volume") or "",
        "Category": product.get("category") or "",
        "Description": product.get("description") or "",
        "Stock Status": product.get("stock_status") or "",
        "Location": product.get("location") or "",
        "Contact Info": product.get("contact_info") or "",
        "Original Message": message.get("content") or "",
        "Channel": message.get("channel") or "",
        "Channel Username": message.get("channel_username") or "",
        "Message Timestamp": message.get("timestamp") or "",
        "Forwarded By": message.get("forwarded_by") or "",
        "Import Timestamp": now,
        "Last Updated": now,
        "Extraction Confidence Score": product.get("extraction_confidence") or product.get("confidence") or 0,
        "Confidence": product.get("confidence") or 0,
        "Status": product.get("status") or "imported",
        "Batch ID": message.get("batch_id") or "",
        "Previous Sale Price": product.get("previous_sale_price") or "",
        "Previous Consumer Price": product.get("previous_consumer_price") or "",
        "Price Changed At": product.get("price_changed_at") or "",
        "Latest Offer ID": product.get("latest_offer_id") or "",
        "Offer Count": product.get("offer_count") or "",
        "Min Qty": product.get("min_qty") or "",
        "Image File ID": product.get("image_file_id") or "",
        "Image File Unique ID": product.get("image_file_unique_id") or "",
        "Brand": product.get("brand") or "",
        "Weight": product.get("weight") or "",
        "Package Count": product.get("package_count") or "",
        "Reference Snapshot ID": product.get("reference_snapshot_id") or "",
        "Reference Match ID": product.get("reference_match_id") or "",
        "Reference Price Role": product.get("reference_price_role") or "",
        "Reference Decision ID": product.get("reference_decision_id") or "",
    })


def _record_batch_status(store, batch_id: str, expected: int, written: int, extraction: dict[str, Any]) -> None:
    try:
        store.ensure("BatchStatus", BATCH_STATUS_HEADERS)
        headers = _headers(store, "BatchStatus", BATCH_STATUS_HEADERS)
        store.append("BatchStatus", [_row_from_map(headers, {
            "Timestamp": _now(),
            "Batch ID": batch_id,
            "Expected Messages": expected,
            "Written Messages": written,
            "Processed Messages": extraction.get("processed") or 0,
            "Extraction Status": extraction.get("status") or "",
            "Rollback": bool(extraction.get("rollback")),
        })])
    except Exception:
        logger.exception("Could not record batch status")


def _log_issue(store, severity: str, message_id: Any, description: str) -> None:
    try:
        store.ensure("ExecutionLogs", EXECUTION_LOG_HEADERS)
        headers = _headers(store, "ExecutionLogs", EXECUTION_LOG_HEADERS)
        store.append("ExecutionLogs", [_row_from_map(headers, {
            "Timestamp": _now(),
            "Action": "price_extraction",
            "Function": "price_extraction",
            "Level": severity,
            "Channel": "",
            "Content Length": "",
            "ContentLength": "",
            "Message": f"Message {message_id}",
            "Error Code": 0,
            "ProductsFound": 0,
            "Details": description,
        })])
    except Exception:
        logger.error("Could not append extraction log")


def _check_systemic_errors(store) -> None:
    try:
        rows = store.read("ExecutionLogs")
    except Exception:
        return
    if len(rows) < 2:
        return
    headers = rows[0]
    action_idx = _first_index(headers, ["Action", "Function"])
    level_idx = _first_index(headers, ["Level"])
    if action_idx is None or level_idx is None:
        return
    window = rows[-200:]
    total = 0
    errors = 0
    for row in window:
        action = row[action_idx] if action_idx < len(row) else ""
        level = row[level_idx] if level_idx < len(row) else ""
        if action == "price_extraction":
            total += 1
            if level in ("ERROR", "WARN"):
                errors += 1
    if total and errors / total > 0.05:
        _log_issue(store, "ALERT", "", f"Systemic price extraction issues: {errors / total * 100:.1f}%")


def _count_batch_messages(store, batch_id: str) -> int:
    rows = store.read("MessageData")
    if len(rows) < 2 or "Batch ID" not in rows[0]:
        return 0
    index = rows[0].index("Batch ID")
    return sum(1 for row in rows[1:] if index < len(row) and str(row[index] or "") == batch_id)


def _list_batch_message_ids(store, batch_id: str) -> list[str]:
    rows = store.read("MessageData")
    if len(rows) < 2:
        return []
    headers = rows[0]
    if "ID" not in headers or "Batch ID" not in headers:
        return []
    id_idx = headers.index("ID")
    batch_idx = headers.index("Batch ID")
    return [
        str(row[id_idx] or "")
        for row in rows[1:]
        if batch_idx < len(row) and str(row[batch_idx] or "") == batch_id and id_idx < len(row)
    ]


def _rollback_catalog(store, batch_id: str) -> None:
    """Drop this batch's offers and rebuild the current rows they changed."""
    keys = _keys_in_batch(store, "Offers", batch_id)
    keys.extend(_keys_in_batch(store, "Products", batch_id))
    _delete_rows_matching(store, "Offers", "Batch ID", batch_id)
    _restore_current_book(store, _dedupe_keys(keys))


def _restore_current_book(store, keys: list[tuple[str, str]]) -> int | None:
    """Rewrite current-book rows from the offer log. Returns the last row written."""
    if not keys:
        return None
    store.ensure("Products", PRODUCT_HEADERS)
    offer_rows = _read_sheet_if_present(store, "Offers")
    _, offers = _records(offer_rows)
    products = _fresh_read(store,"Products")
    headers = _pad_headers(products[0] if products else PRODUCT_HEADERS)
    queue=_sheet_sync_queue()
    review_ledger=_reference_item_review_ledger()
    active_reference_id=_active_reference_snapshot_id(review_ledger) if review_ledger else ''
    last_row = None
    deletions: list[int] = []
    for name, channel in keys:
        matching = _offers_for_product(offers, name, channel)
        row_number = _find_product_row(products, headers, name, channel)
        if not row_number and matching:
            row_number=_find_product_row_by_offer_ids(products,headers,{str(o.get('Offer ID') or '') for o in matching})
        if not matching:
            if row_number:
                deletions.append(row_number)
            continue
        latest = matching[-1]
        existing = products[row_number - 1] if row_number else None
        product = _remember_sheet_prices(
            _product_from_offers(matching),
            existing,
            headers,
            str(latest.get("Import Timestamp") or ""),
        )
        _apply_reference_item_review(product, latest, review_ledger, active_reference_id)
        new_row = _product_row(headers, product, _message_from_offer(latest))
        _set_cell(new_row, headers, "Last Updated", latest.get("Import Timestamp") or "")
        if not existing:
            _set_cell(new_row, headers, "Import Timestamp", matching[0].get("Import Timestamp") or "")
        if existing and queue is not None:
            base_row=None
            if len(matching)>1:
                prior=matching[:-1]
                base_product=_product_from_offers(prior)
                base_message=_message_from_offer(prior[-1])
                base_row=_product_row(headers,base_product,base_message)
            merged,row_number=_reconcile_current_row(store,queue,row_number,existing,headers,base_row,new_row,
                product_id=str(_cell(existing,headers,'Product ID') or ''),
                product_name=str(name),channel=str(channel),source_revision=str(latest.get('Offer ID') or ''))
        else:
            merged = _merge_current_row(existing, headers, new_row)
        if row_number:
            if existing and queue is not None:
                # Reconciliation writes only approved server-side cells, after a fresh read.
                pass
            else:
                store.write_rows("Products", row_number, [merged])
            products[row_number - 1] = merged
            last_row = row_number
        else:
            _append_at_next_row(store, "Products", headers, [merged])
            products.append(merged)
            last_row = len(products)
    if deletions:
        store.delete_rows("Products", deletions)
    return last_row


def _sheet_sync_queue():
    path=os.getenv('PIPELINE_DB_PATH') or ''
    if not path:return None
    from catalog.durable import Handoffs
    return Handoffs(path)


def _reference_item_review_ledger():
    path = os.getenv('REFERENCE_STORE_PATH') or ''
    owners = [x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()]
    if not path or not owners:
        return None
    from reference_import.item_reviews import ItemReviewLedger
    return ItemReviewLedger(path, owner_ids=owners)


def _active_reference_snapshot_id(ledger):
    if ledger is None:
        return ''
    with ledger.connect() as db:
        row = db.execute('SELECT snapshot_id FROM reference_active WHERE singleton=1').fetchone()
    return str(row['snapshot_id']) if row else ''


def _apply_reference_item_review(product, offer, ledger, active_snapshot_id):
    if ledger is None:
        return product
    snapshot_id = str(offer.get('Reference Snapshot ID') or '')
    offer_id = str(offer.get('Offer ID') or '')
    if not snapshot_id or snapshot_id != active_snapshot_id or not offer_id:
        return product
    from reference_import.item_reviews import review_case_id
    decision = ledger.get(review_case_id(offer_id, snapshot_id))
    if not decision:
        decision = ledger.get_by_decision_id(str(offer.get('Reference Decision ID') or ''))
    if not decision or decision.get('snapshot_id') != active_snapshot_id:
        return product
    product['reference_snapshot_id'] = snapshot_id
    product['reference_decision_id'] = decision.get('decision_id') or ''
    if decision.get('choice') == 'no_match':
        product['reference_match_id'] = ''
        product['reference_price_role'] = 'unresolved'
        product['status'] = 'needs_review'
        return product
    if decision.get('choice') != 'match':
        return product
    product['reference_match_id'] = decision.get('reference_id') or ''
    product['reference_price_role'] = decision.get('price_role') or ''
    if decision.get('price_role') == 'package':
        from reference_import.importer import HEADERS
        from reference_import.matching import package_count
        import json, math
        with ledger.connect() as db:
            row = db.execute('SELECT payload FROM reference_snapshots WHERE snapshot_id=?',
                             (active_snapshot_id,)).fetchone()
        reference_pack = 0
        if row:
            payload = json.loads(row['payload'])
            index = HEADERS.index('Product Code')
            count_index = HEADERS.index('Package Count')
            reference = next((item for item in payload.get('rows', [])
                if str(item[index] or item[HEADERS.index('Normalized Key')]) == decision.get('reference_id')), None)
            if reference:
                try:
                    reference_pack = int(float(reference[count_index] or 0))
                except (TypeError, ValueError):
                    reference_pack = 0
        count = int(package_count({'package_count': product.get('package_count'),
                                   'packaging': product.get('packaging')}) or reference_pack or 0)
        source_cost = _positive_price(offer.get('Sale Price'))
        owner_confirmed_unit = bool(str(offer.get('Owner Decision ID') or '').strip())
        if count > 0 and source_cost and not owner_confirmed_unit:
            unit_cost = int(math.floor(source_cost / count + .5))
            product['sale_price'] = unit_cost
            product['price'] = unit_cost
            product['package_count'] = count
            product['min_qty'] = max(int(_positive_price(product.get('min_qty')) or 0), count)
        elif count > 0 and owner_confirmed_unit:
            product['package_count'] = count
            product['min_qty'] = max(int(_positive_price(product.get('min_qty')) or 0), count)
    reasons = [part.strip() for part in str(offer.get('Review Reason') or '').split(';') if part.strip()]
    if str(product.get('status') or '').lower() == 'needs_review' and reasons and all(
            reason.lower().startswith('reference:') for reason in reasons):
        product['status'] = 'imported'
    return product


def _fresh_read(store,name):
    reader=getattr(store,'read_fresh',None)
    return reader(name) if callable(reader) else store.read(name)


def _find_product_row_by_offer_ids(rows,headers,offer_ids):
    if 'Latest Offer ID' not in headers:return None
    index=headers.index('Latest Offer ID')
    for row_number,row in enumerate(rows[1:],start=2):
        if index<len(row) and str(row[index] or '') in offer_ids:
            return row_number
    return None


def _find_product_row_by_id(rows,headers,product_id):
    if not product_id or 'Product ID' not in headers:return None
    index=headers.index('Product ID')
    for row_number,row in enumerate(rows[1:],start=2):
        if index<len(row) and str(row[index] or '')==product_id:
            return row_number
    return None


def _reconcile_current_row(store,queue,row_number,existing,headers,base_row,server_row,*,product_id,product_name,channel,source_revision):
    observed=list(existing)+['']*max(0,len(headers)-len(existing))
    base=list(base_row or existing)+['']*max(0,len(headers)-len(base_row or existing))
    server=list(server_row)+['']*max(0,len(headers)-len(server_row))
    merged=list(observed[:len(headers)])
    targets={}
    active_conflict_ids=set()
    for index,field in enumerate(headers):
        sheet_value=observed[index]
        server_value=server[index]
        if field in _SHEET_IMMUTABLE_FIELDS:
            value=sheet_value if field=='Product ID' or (field=='Import Timestamp' and sheet_value not in ('',None)) else server_value
            merged[index]=value
            if value!=sheet_value:targets[field]=value
            continue
        result=reconcile_field(field,base[index],server_value,sheet_value)
        if result.action in ('in_sync','server_to_sheet'):
            merged[index]=server_value
            if result.action=='server_to_sheet' and server_value!=sheet_value:targets[field]=server_value
        elif result.action=='sheet_to_server':
            merged[index]=sheet_value
        else:
            row_id=product_id or f'{channel}|{product_name}'
            conflict_id=stable_conflict_id(row_id,field,source_revision,base[index],server_value,sheet_value)
            active_conflict_ids.add(conflict_id)
            conflict=queue.get_sheet_conflict(conflict_id)
            if conflict and conflict['state']=='resolved':
                if conflict['choice']=='server':
                    merged[index]=server_value
                    if server_value!=sheet_value:targets[field]=server_value
                else:
                    merged[index]=sheet_value
                continue
            queue.record_sheet_conflict({
                'conflict_id':conflict_id,'product_id':row_id,'product_name':product_name,
                'channel':channel,'field':field,'source_revision':source_revision,
                'base':base[index],'server':server_value,'sheet':sheet_value,
            })
            merged[index]=sheet_value

    # Locate the row again in a fresh sheet read before changing any cells.
    live_rows=_fresh_read(store,'Products')
    live_headers=[str(x) for x in live_rows[0]] if live_rows else headers
    live_row_number=_find_product_row_by_id(live_rows,live_headers,product_id)
    if not live_row_number:
        prior_offer_id=str(_cell(existing,headers,'Latest Offer ID') or '')
        if prior_offer_id:
            live_row_number=_find_product_row_by_offer_ids(live_rows,live_headers,{prior_offer_id})
    if not live_row_number:
        live_row_number=_find_product_row(live_rows,live_headers,product_name,channel)
    if live_row_number is None or live_row_number<2 or live_row_number>len(live_rows):
        # The row moved, vanished or lost every trusted identity since the first read.
        # Do not fall back to a stale row number and overwrite an unrelated product.
        return merged,row_number
    live=list(live_rows[live_row_number-1])+['']*max(0,len(live_headers)-len(live_rows[live_row_number-1]))
    safe_targets={}
    for field,value in targets.items():
        if field not in live_headers:continue
        current=live[live_headers.index(field)]
        originally=observed[headers.index(field)] if field in headers else ''
        if (not field_values_equal(field,current,originally) and
                not field_values_equal(field,current,value)):
            # Another edit arrived after the first read. Keep it and record both values.
            row_id=product_id or f'{channel}|{product_name}'
            base_value=base[headers.index(field)] if field in headers else ''
            conflict_id=stable_conflict_id(row_id,field,source_revision,base_value,value,current)
            active_conflict_ids.add(conflict_id)
            queue.record_sheet_conflict({'conflict_id':conflict_id,'product_id':row_id,
                'product_name':product_name,'channel':channel,'field':field,'source_revision':source_revision,
                'base':base_value,'server':value,'sheet':current})
            merged[headers.index(field)]=current
            continue
        safe_targets[field]=value
    if safe_targets:
        writer=getattr(store,'write_cells',None)
        if callable(writer):writer('Products',live_row_number,live_headers,safe_targets)
        else:
            for field,value in safe_targets.items():live[live_headers.index(field)]=value
            store.write_rows('Products',live_row_number,[live[:len(live_headers)]])
        confirmed=_fresh_read(store,'Products')
        if live_row_number<=len(confirmed):
            actual=list(confirmed[live_row_number-1])+['']*max(0,len(live_headers)-len(confirmed[live_row_number-1]))
            for field,value in safe_targets.items():
                idx=live_headers.index(field)
                if not field_values_equal(field,actual[idx],value):
                    row_id=product_id or f'{channel}|{product_name}'
                    base_value=base[headers.index(field)] if field in headers else ''
                    conflict_id=stable_conflict_id(row_id,field,source_revision,base_value,value,actual[idx])
                    active_conflict_ids.add(conflict_id)
                    queue.record_sheet_conflict({'conflict_id':conflict_id,'product_id':row_id,
                        'product_name':product_name,'channel':channel,'field':field,'source_revision':source_revision,
                        'base':base_value,'server':value,'sheet':actual[idx]})
                    merged[headers.index(field)]=actual[idx]
    queue.supersede_sheet_conflicts(product_id or f'{channel}|{product_name}',channel,active_conflict_ids)
    return merged,live_row_number


def _offer_fields(product: dict[str, Any], message: dict[str, Any], offer_id: str, imported_at: str, review_reason: str) -> dict[str, Any]:
    return {
        "Offer ID": offer_id,
        "Product Name": product.get("name") or "",
        "Channel Username": message.get("channel_username") or "",
        "Channel": message.get("channel") or "",
        "Sale Price": product.get("sale_price") if product.get("sale_price") is not None else "",
        "Consumer Price": product.get("consumer_price") if product.get("consumer_price") is not None else "",
        "Price": product.get("price") if product.get("price") is not None else "",
        "Actual Price": product.get("actual_price") if product.get("actual_price") is not None else "",
        "Price Type": product.get("price_type") or "",
        "Currency": product.get("currency") or "",
        "Packaging": product.get("packaging") or "",
        "Volume": product.get("volume") or "",
        "Package Price": product.get("package_price") or "",
        "Category": product.get("category") or "",
        "Variation Type": product.get("variation_type") or "",
        "Stock Status": product.get("stock_status") or "",
        "Description": product.get("description") or "",
        "Status": product.get("status") or "imported",
        "Review Reason": review_reason if product.get("status") == "needs_review" else "",
        "Extraction Confidence Score": product.get("extraction_confidence") or product.get("confidence") or 0,
        "Confidence": product.get("confidence") or 0,
        "Message ID": message.get("id") or "",
        "Batch ID": message.get("batch_id") or "",
        "Message Timestamp": message.get("timestamp") or "",
        "Forwarded By": message.get("forwarded_by") or "",
        "Import Timestamp": imported_at,
        "Original Message": message.get("content") or "",
        "Min Qty": product.get("min_qty") or minimum_purchase(product.get("packaging") or "", product.get("name") or ""),
        "Image File ID": message.get("image_file_id") or "",
        "Image File Unique ID": message.get("image_file_unique_id") or "",
        "Brand": product.get("brand") or "",
        "Weight": product.get("weight") or "",
        "Package Count": product.get("package_count") or "",
        "Reference Decision ID": "",
        "Derived From Offer ID": "",
        "Owner Decision ID": "",
        "Owner Decision Actor": "",
        "Owner Decision At": "",
        "Detected Price": product.get("detected_price") if product.get("detected_price") is not None else "",
        "Price Anchor Type": product.get("price_anchor_type") or "",
        "Price Anchor ID": product.get("price_anchor_id") or "",
        "Price Anchor Name": product.get("price_anchor_name") or "",
        "Price Anchor Match Score": product.get("price_anchor_score") or "",
        "Price Scale Method": product.get("price_scale_method") or "",
        "Price Scale Status": product.get("price_scale_status") or "",
        "Price Scale Reason": product.get("price_scale_reason") or "",
        "Historical Median Unit Price": product.get("historical_median_unit_price") or "",
        "Category Reference Count": product.get("category_reference_count") or "",
    }


def _product_from_offers(offers: list[dict[str, Any]]) -> dict[str, Any]:
    latest = offers[-1]
    image_id = ""
    image_unique = ""
    for offer in reversed(offers):
        candidate = str(offer.get("Image File ID") or "")
        if candidate:
            image_id = candidate
            image_unique = str(offer.get("Image File Unique ID") or "")
            break
    return {
        "name": latest.get("Product Name") or "",
        "variation_type": latest.get("Variation Type") or "",
        "brand": latest.get("Brand") or "",
        "weight": latest.get("Weight") or "",
        "package_count": latest.get("Package Count") or "",
        "sale_price": latest.get("Sale Price"),
        "actual_price": latest.get("Actual Price"),
        "price_type": latest.get("Price Type") or "",
        "price": latest.get("Price"),
        "consumer_price": latest.get("Consumer Price"),
        "currency": latest.get("Currency") or "",
        "packaging": latest.get("Packaging") or "",
        "volume": latest.get("Volume") or "",
        "double_pack_price": latest.get("Package Price") or "",
        "category": latest.get("Category") or "",
        "description": latest.get("Description") or "",
        "stock_status": latest.get("Stock Status") or "",
        "extraction_confidence": latest.get("Extraction Confidence Score") or 0,
        "confidence": latest.get("Confidence") or 0,
        "status": latest.get("Status") or "imported",
        "previous_sale_price": _previous_different_price(offers, "Sale Price"),
        "previous_consumer_price": _previous_consumer_price(offers),
        "price_changed_at": _price_changed_at(offers),
        "latest_offer_id": latest.get("Offer ID") or "",
        "reference_snapshot_id": latest.get("Reference Snapshot ID") or "",
        "reference_match_id": latest.get("Reference Match ID") or "",
        "reference_price_role": latest.get("Reference Price Role Hint") or "",
        "reference_decision_id": latest.get("Reference Decision ID") or "",
        "offer_count": len(offers),
        "min_qty": max(
            int(_positive_price(latest.get("Min Qty")) or 0),
            minimum_purchase(latest.get("Packaging") or "", latest.get("Product Name") or ""),
        ),
        "image_file_id": image_id,
        "image_file_unique_id": image_unique,
    }


def _message_from_offer(offer: dict[str, Any]) -> dict[str, Any]:
    return {
        "channel_username": offer.get("Channel Username") or "",
        "channel": offer.get("Channel") or "",
        "content": offer.get("Original Message") or "",
        "timestamp": offer.get("Message Timestamp") or "",
        "forwarded_by": offer.get("Forwarded By") or "",
        "batch_id": offer.get("Batch ID") or "",
    }


def _previous_different_price(offers: list[dict[str, Any]], field: str) -> int | str:
    latest = _positive_price(offers[-1].get(field))
    if latest is None:
        return ""
    for older in reversed(offers[:-1]):
        older_price = _positive_price(older.get(field))
        if older_price is not None and older_price != latest:
            return older_price
    return ""


def _previous_consumer_price(offers: list[dict[str, Any]]) -> int | str:
    latest = _consumer_price(offers[-1])
    if latest is None:
        return ""
    for older in reversed(offers[:-1]):
        older_price = _consumer_price(older)
        if older_price is not None and older_price != latest:
            return older_price
    return ""


def _price_changed_at(offers: list[dict[str, Any]]) -> str:
    if len(offers) < 2:
        return ""
    latest_sale = _positive_price(offers[-1].get("Sale Price"))
    latest_consumer = _consumer_price(offers[-1])
    changed_at = str(offers[-1].get("Import Timestamp") or "")
    for older in reversed(offers[:-1]):
        same_sale = _positive_price(older.get("Sale Price")) == latest_sale
        same_consumer = _consumer_price(older) == latest_consumer
        if not (same_sale and same_consumer):
            break
        if older.get("Import Timestamp"):
            changed_at = str(older.get("Import Timestamp") or "")
    return changed_at


def _positive_price(value: Any) -> int | None:
    if value is None or value == "":
        return None
    try:
        number = int(float(value))
    except (TypeError, ValueError):
        return None
    return number if number > 0 else None


def _consumer_price(offer: dict[str, Any]) -> int | None:
    return _positive_price(offer.get("Consumer Price")) or _positive_price(offer.get("Actual Price"))


def _offers_for_product(offers: list[dict[str, Any]], name: str, channel: str) -> list[dict[str, Any]]:
    target = _product_key(name)
    return [
        offer for offer in offers
        if _product_key(str(offer.get("Product Name") or "")) == target
        and (offer.get("Channel Username") or "") == channel
    ]


def _opening_offer_rows(store, keys: list[tuple[str, str]], offer_headers: list[str], existing_offers: list[list[Any]]) -> list[list[Any]]:
    """Record the price already on the sheet once, so later changes keep it."""
    products = _read_sheet_if_present(store, "Products")
    if len(products) < 2:
        return []
    headers = [str(header) for header in products[0]]
    _, known = _records(existing_offers)
    rows = []
    seen = set()
    for name, channel in keys:
        marker = ((name or "").strip().lower(), channel or "")
        if marker in seen or _offers_for_product(known, name, channel):
            continue
        seen.add(marker)
        row_number = _find_product_row(products, headers, name, channel)
        if not row_number:
            continue
        existing = products[row_number - 1]
        sale = _positive_price(_cell(existing, headers, "Sale Price"))
        consumer = _first_positive(existing, headers, ("Consumer Price", "Actual Price"))
        if sale is None and consumer is None:
            continue
        imported_at = str(_cell(existing, headers, "Last Updated") or _cell(existing, headers, "Import Timestamp") or "")
        rows.append(_row_from_map(offer_headers, {
            "Offer ID": f"sheet:{channel}:{marker[0]}",
            "Product Name": name,
            "Channel Username": channel,
            "Channel": _cell(existing, headers, "Channel"),
            "Sale Price": sale if sale is not None else "",
            "Consumer Price": consumer if consumer is not None else "",
            "Price": sale if sale is not None else consumer,
            "Actual Price": consumer if consumer is not None else "",
            "Price Type": _cell(existing, headers, "Price Type"),
            "Currency": _cell(existing, headers, "Currency"),
            "Packaging": _cell(existing, headers, "Packaging"),
            "Volume": _cell(existing, headers, "Volume"),
            "Package Price": _cell(existing, headers, "Double Pack Price"),
            "Category": _cell(existing, headers, "Category"),
            "Stock Status": _cell(existing, headers, "Stock Status"),
            "Description": _cell(existing, headers, "Description"),
            "Status": "imported",
            "Batch ID": "sheet",
            "Message Timestamp": _cell(existing, headers, "Message Timestamp"),
            "Forwarded By": _cell(existing, headers, "Forwarded By"),
            "Import Timestamp": imported_at,
            "Original Message": _cell(existing, headers, "Original Message"),
        }))
    return rows


def _unseen_offer_rows(existing: list[list[Any]], headers: list[str], rows: list[list[Any]]) -> list[list[Any]]:
    if "Offer ID" not in headers:
        return rows
    index = headers.index("Offer ID")
    seen = {
        str(row[index])
        for row in existing[1:]
        if index < len(row) and row[index] not in ("", None)
    }
    return [row for row in rows if index >= len(row) or str(row[index]) not in seen]


def _keys_in_batch(store, sheet: str, batch_id: str) -> list[tuple[str, str]]:
    rows = _read_sheet_if_present(store, sheet)
    if len(rows) < 2:
        return []
    headers = [str(header) for header in rows[0]]
    if "Batch ID" not in headers or "Product Name" not in headers:
        return []
    batch_index = headers.index("Batch ID")
    name_index = headers.index("Product Name")
    channel_index = headers.index("Channel Username") if "Channel Username" in headers else None
    keys = []
    for row in rows[1:]:
        if batch_index >= len(row) or str(row[batch_index] or "") != str(batch_id):
            continue
        name = row[name_index] if name_index < len(row) else ""
        channel = row[channel_index] if channel_index is not None and channel_index < len(row) else ""
        keys.append((str(name or ""), str(channel or "")))
    return keys


def _dedupe_keys(keys: list[tuple[str, str]]) -> list[tuple[str, str]]:
    seen = set()
    unique = []
    for name, channel in keys:
        marker = ((name or "").strip().lower(), channel or "")
        if marker in seen:
            continue
        seen.add(marker)
        unique.append((name, channel))
    return unique


def _delete_rows_matching(store, sheet: str, header: str, value: str) -> int:
    rows = _read_sheet_if_present(store, sheet)
    if len(rows) < 2 or header not in [str(item) for item in rows[0]]:
        return 0
    headers = [str(item) for item in rows[0]]
    index = headers.index(header)
    matches = [
        row_number
        for row_number, row in enumerate(rows, start=1)
        if row_number > 1 and index < len(row) and str(row[index] or "") == str(value)
    ]
    if not matches:
        return 0
    return store.delete_rows(sheet, matches)


def _remember_sheet_prices(
    product: dict[str, Any],
    existing: list[Any] | None,
    headers: list[str],
    offer_time: str,
) -> dict[str, Any]:
    """Keep a pre-log sheet price as history when the offer log does not have it."""
    if not existing:
        return product
    changed = _remember_price(product, existing, headers, "previous_sale_price", "sale_price", ("Sale Price",))
    changed = _remember_price(
        product,
        existing,
        headers,
        "previous_consumer_price",
        "consumer_price",
        ("Consumer Price", "Actual Price"),
    ) or changed
    if changed and not product.get("price_changed_at"):
        product["price_changed_at"] = offer_time
    elif not product.get("price_changed_at"):
        changed_at = _cell(existing, headers, "Price Changed At")
        if changed_at:
            product["price_changed_at"] = changed_at
    return product


def _remember_price(
    product: dict[str, Any],
    existing: list[Any],
    headers: list[str],
    previous_field: str,
    current_field: str,
    columns: tuple[str, ...],
) -> bool:
    if product.get(previous_field) not in ("", None):
        return False
    new_price = _positive_price(product.get(current_field))
    if current_field == "consumer_price" and new_price is None:
        new_price = _positive_price(product.get("actual_price"))
    old_price = _first_positive(existing, headers, columns)
    if new_price is None or old_price is None:
        return False
    if old_price != new_price:
        product[previous_field] = old_price
        return True
    kept = _positive_price(_cell(existing, headers, "Previous Sale Price" if previous_field == "previous_sale_price" else "Previous Consumer Price"))
    if kept is not None:
        product[previous_field] = kept
    return False


def _first_positive(row: list[Any], headers: list[str], columns: tuple[str, ...]) -> int | None:
    for column in columns:
        price = _positive_price(_cell(row, headers, column))
        if price is not None:
            return price
    return None


def _cell(row: list[Any] | None, headers: list[str], header: str) -> Any:
    if not row or header not in headers:
        return ""
    index = headers.index(header)
    return row[index] if index < len(row) else ""


def _merge_current_row(existing: list[Any] | None, headers: list[str], new_row: list[Any]) -> list[Any]:
    owned = set(PRODUCT_HEADERS)
    if not existing:
        return list(new_row)
    merged = list(existing) + [""] * max(0, len(headers) - len(existing))
    merged = merged[:len(headers)]
    for index, header in enumerate(headers):
        if header in owned and header not in _PRESERVE_ON_UPDATE:
            merged[index] = new_row[index] if index < len(new_row) else ""
    for header in _PRESERVE_ON_UPDATE:
        if header not in headers:
            continue
        index = headers.index(header)
        current = existing[index] if index < len(existing) else ""
        if current not in ("", None):
            merged[index] = current
        elif index < len(new_row):
            merged[index] = new_row[index]
    for header in ("Image File ID", "Image File Unique ID"):
        if header not in headers:
            continue
        index = headers.index(header)
        incoming = new_row[index] if index < len(new_row) else ""
        current = existing[index] if index < len(existing) else ""
        if incoming in ("", None) and current not in ("", None):
            merged[index] = current
    return merged


def _records(rows: list[list[Any]]) -> tuple[list[str], list[dict[str, Any]]]:
    if not rows:
        return [], []
    headers = [str(header) for header in rows[0]]
    records = []
    for row in rows[1:]:
        records.append({
            header: row[index] if index < len(row) else ""
            for index, header in enumerate(headers)
        })
    return headers, records


def _read_sheet_if_present(store, name: str) -> list[list[Any]]:
    try:
        if isinstance(store, MemoryStore) and name not in store.sheets:
            return []
        return store.read(name)
    except RuntimeError as exc:
        if "429" in str(exc) or "503" in str(exc):
            raise
        logger.error("Could not read sheet %s", name)
        return []
    except Exception:
        logger.error("Could not read sheet %s", name)
        return []


def _set_cell(row: list[Any], headers: list[str], header: str, value: Any) -> None:
    if header in headers:
        row[headers.index(header)] = "" if value is None else value


def _append_at_next_row(store, name: str, headers: list[str], rows: list[list[Any]]) -> None:
    existing = store.read(name)
    start = max(len(existing) + 1, 2)
    if isinstance(store, MemoryStore):
        store.append(name, rows)
        return
    store.write_rows(name, start, rows)


def _headers(store, name: str, fallback: list[str]) -> list[str]:
    rows = store.read(name)
    if rows and rows[0]:
        return _pad_headers(rows[0])
    return list(fallback)


def _pad_headers(headers: list[Any]) -> list[str]:
    return [str(header) for header in headers]


def _row_from_map(headers: list[str], data_map: dict[str, Any]) -> list[Any]:
    row: list[Any] = [""] * len(headers)
    for index, header in enumerate(headers):
        if header in data_map and data_map[header] is not None:
            row[index] = data_map[header]
    return row


def _product_id(product_name: str) -> str:
    slug = re.sub(r"[^a-z0-9]", "_", (product_name or "").lower())[:20]
    return f"{slug}_{str(int(time.time() * 1000))[-6:]}"


def _first_index(headers: list[Any], names: list[str]) -> int | None:
    for name in names:
        if name in headers:
            return headers.index(name)
    return None


def _now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%S.000Z", time.gmtime())


def _quote(name: str) -> str:
    return "'" + name.replace("'", "''") + "'"


def _a1(name: str) -> str:
    return _quote(name)


def _column(index: int) -> str:
    letters = ""
    while index:
        index, remainder = divmod(index - 1, 26)
        letters = chr(65 + remainder) + letters
    return letters
