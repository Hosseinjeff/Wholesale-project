"""Write catalog batches straight to Google Sheets with a service account."""

from __future__ import annotations

import json
import logging
import os
import re
import threading
import time
from typing import Any

from catalog.extract import (
    BATCH_STATUS_HEADERS,
    EXECUTION_LOG_HEADERS,
    MESSAGE_HEADERS,
    PRODUCT_HEADERS,
    classify_message,
    extract_products,
    quality_check,
)

logger = logging.getLogger(__name__)

_WRITE_LOCK = threading.Lock()
SHEETS_SCOPE = "https://www.googleapis.com/auth/spreadsheets"


class MemoryStore:
    """In-memory sheet used by tests and as the store contract."""

    def __init__(self) -> None:
        self.sheets: dict[str, list[list[Any]]] = {}

    def ensure(self, name: str, headers: list[str]) -> None:
        if name not in self.sheets:
            self.sheets[name] = [list(headers)]

    def read(self, name: str) -> list[list[Any]]:
        return [list(row) for row in self.sheets.get(name, [])]

    def append(self, name: str, rows: list[list[Any]]) -> None:
        self.sheets.setdefault(name, [])
        self.sheets[name].extend(rows)

    def write_rows(self, name: str, start_row: int, rows: list[list[Any]]) -> None:
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


class GoogleSheetStore:
    def __init__(self, spreadsheet_id: str, info: dict[str, Any]) -> None:
        self.spreadsheet_id = spreadsheet_id
        self._info = info
        self._service = None
        self._sheet_ids: dict[str, int] = {}

    def _api(self):
        if self._service is None:
            from google.oauth2.service_account import Credentials
            from googleapiclient.discovery import build

            creds = Credentials.from_service_account_info(self._info, scopes=[SHEETS_SCOPE])
            self._service = build("sheets", "v4", credentials=creds, cache_discovery=False)
        return self._service

    def _refresh_sheet_ids(self) -> None:
        meta = self._api().spreadsheets().get(
            spreadsheetId=self.spreadsheet_id,
            fields="sheets.properties",
        ).execute()
        self._sheet_ids = {
            sheet["properties"]["title"]: sheet["properties"]["sheetId"]
            for sheet in meta.get("sheets", [])
        }

    def ensure(self, name: str, headers: list[str]) -> None:
        if not self._sheet_ids:
            self._refresh_sheet_ids()
        if name not in self._sheet_ids:
            self._api().spreadsheets().batchUpdate(
                spreadsheetId=self.spreadsheet_id,
                body={"requests": [{"addSheet": {"properties": {"title": name}}}]},
            ).execute()
            self._refresh_sheet_ids()
        existing = self.read(name)
        if not existing:
            self.write_rows(name, 1, [headers])

    def read(self, name: str) -> list[list[Any]]:
        result = self._api().spreadsheets().values().get(
            spreadsheetId=self.spreadsheet_id,
            range=_a1(name),
        ).execute()
        return result.get("values", [])

    def append(self, name: str, rows: list[list[Any]]) -> None:
        if not rows:
            return
        width = max(len(row) for row in rows)
        self._api().spreadsheets().values().append(
            spreadsheetId=self.spreadsheet_id,
            range=f"{_quote(name)}!A1:{_column(width)}1",
            valueInputOption="RAW",
            insertDataOption="INSERT_ROWS",
            body={"values": rows},
        ).execute()

    def write_rows(self, name: str, start_row: int, rows: list[list[Any]]) -> None:
        if not rows:
            return
        width = max(len(row) for row in rows)
        end = start_row + len(rows) - 1
        self._api().spreadsheets().values().update(
            spreadsheetId=self.spreadsheet_id,
            range=f"{_quote(name)}!A{start_row}:{_column(width)}{end}",
            valueInputOption="RAW",
            body={"values": rows},
        ).execute()

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
        self._api().spreadsheets().batchUpdate(
            spreadsheetId=self.spreadsheet_id,
            body={"requests": requests},
        ).execute()
        return len(requests)


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


def finalize_batch(store: MemoryStore | GoogleSheetStore, payload: dict[str, Any]) -> dict[str, Any]:
    with _WRITE_LOCK:
        return _finalize_batch(store, payload)


def _finalize_batch(store: MemoryStore | GoogleSheetStore, payload: dict[str, Any]) -> dict[str, Any]:
    batch_id = str(payload.get("batch_id") or "")
    messages = payload.get("messages") if isinstance(payload.get("messages"), list) else []
    expected = int(payload.get("expected_count") or 0) or len(messages) or 0
    written = 0

    if messages:
        store.ensure("MessageData", MESSAGE_HEADERS)
        headers = _headers(store, "MessageData", MESSAGE_HEADERS)
        rows = []
        imported_at = _now()
        for message in messages:
            message = dict(message or {})
            message["batch_id"] = batch_id
            classification = classify_message(message.get("content") or "", message.get("channel_username") or message.get("channel") or "")
            rows.append(_row_from_map(headers, {
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
            }))
        if rows:
            _append_at_next_row(store, "MessageData", headers, rows)
            written = len(rows)
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

    extraction = _process_batch_products(store, batch_id, messages)
    _record_batch_status(store, batch_id, expected, written, extraction)
    return {
        "status": "success",
        "ack": "ingestion_complete",
        "batch_id": batch_id,
        "expected_count": expected,
        "written_count": written,
        "extraction_status": extraction["status"],
        "processed_messages": extraction["processed"],
        "rollback": bool(extraction.get("rollback")),
    }


def _process_batch_products(store, batch_id: str, messages: list[dict[str, Any]]) -> dict[str, Any]:
    processed = 0
    items = messages or [{"id": message_id, "batch_id": batch_id} for message_id in _list_batch_message_ids(store, batch_id)]
    for message in items:
        message = dict(message or {})
        message["batch_id"] = batch_id
        ok = False
        for _attempt in range(3):
            result = import_product_data(store, message)
            ok = bool(result and result.get("success"))
            if ok:
                break
        if not ok:
            _delete_products_by_batch(store, batch_id)
            return {"status": "error", "processed": processed, "rollback": True}
        processed += 1
    return {"status": "success", "processed": processed}


def import_product_data(store, data: dict[str, Any]) -> dict[str, Any]:
    try:
        _fill_message_from_sheet(store, data)
        store.ensure("Products", PRODUCT_HEADERS)
        products = extract_products(data.get("content") or "", data.get("channel_username") or "")
        if not products:
            return {"success": True, "products_found": 0, "row": None}

        existing = store.read("Products")
        headers = _pad_headers(existing[0] if existing else PRODUCT_HEADERS)
        last_row = None
        for product in products:
            qa = quality_check(
                {
                    "name": product.get("name"),
                    "sale_price": product.get("sale_price") or product.get("price") or 0,
                    "actual_price": product.get("actual_price") or product.get("consumer_price") or 0,
                    "extraction_confidence": product.get("extraction_confidence") or product.get("confidence") or 0,
                },
                existing,
                headers,
            )
            product["status"] = "needs_review" if qa["requires_review"] else "imported"
            if qa["requires_review"]:
                _log_issue(store, "WARN", data.get("id"), qa["reason"])

            existing_row = _find_product_row(existing, headers, product.get("name") or "", data.get("channel_username") or "")
            if existing_row:
                updated = _updated_product_row(existing[existing_row - 1], headers, product, data)
                store.write_rows("Products", existing_row, [updated])
                existing[existing_row - 1] = updated
                last_row = existing_row
            else:
                row = _product_row(headers, product, data)
                store.append("Products", [row])
                existing.append(row)
                last_row = len(existing)

        _check_systemic_errors(store)
        return {"success": True, "products_found": len(products), "row": last_row}
    except Exception as exc:
        logger.error("Product import failed: %s", exc.__class__.__name__)
        return {"success": False, "error": exc.__class__.__name__}


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
    target = product_name.lower()
    for index, row in enumerate(rows[1:], start=2):
        existing_name = row[name_idx] if name_idx < len(row) else ""
        existing_channel = row[channel_idx] if channel_idx < len(row) else ""
        if existing_name and str(existing_name).lower() == target and existing_channel == channel_username:
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
    })


def _updated_product_row(current: list[Any], headers: list[str], product: dict[str, Any], message: dict[str, Any]) -> list[Any]:
    row = list(current) + [""] * max(0, len(headers) - len(current))
    updates = {
        "Channel ID": message.get("channel_username") or message.get("channel") or "",
        "Product Name": product.get("name") or "",
        "Variation Type": product.get("variation_type") or "",
        "Sale Price": product.get("sale_price"),
        "Actual Price": product.get("actual_price"),
        "Price Type": product.get("price_type") or "",
        "Price": product.get("price"),
        "Currency": product.get("currency") or "",
        "Consumer Price": product.get("consumer_price"),
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
        "Last Updated": _now(),
        "Extraction Confidence Score": product.get("extraction_confidence") or product.get("confidence") or 0,
        "Confidence": product.get("confidence") or 0,
        "Status": product.get("status") or "updated",
    }
    keep_existing_when_missing = {
        "Sale Price",
        "Actual Price",
        "Price",
        "Consumer Price",
        "Description",
    }
    for header, value in updates.items():
        if header not in headers:
            continue
        if header in keep_existing_when_missing and value is None:
            continue
        row[headers.index(header)] = "" if value is None else value
    return row[: len(headers)]


def _record_batch_status(store, batch_id: str, expected: int, written: int, extraction: dict[str, Any]) -> None:
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


def _delete_products_by_batch(store, batch_id: str) -> int:
    rows = store.read("Products")
    if len(rows) < 2 or "Batch ID" not in rows[0]:
        return 0
    index = rows[0].index("Batch ID")
    matches = [row_number for row_number, row in enumerate(rows, start=1) if row_number > 1 and index < len(row) and str(row[index] or "") == batch_id]
    return store.delete_rows("Products", matches)


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
