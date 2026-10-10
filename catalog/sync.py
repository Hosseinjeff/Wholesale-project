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
    normalize,
    parse_price,
    quality_check,
)

logger = logging.getLogger(__name__)

_WRITE_LOCK = threading.Lock()
SHEETS_SCOPE = "https://www.googleapis.com/auth/spreadsheets"
_PRESERVE_ON_UPDATE = ("Product ID", "Import Timestamp")


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
        self._grids: dict[str, dict[str, int]] = {}

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
            self._api().spreadsheets().batchUpdate(
                spreadsheetId=self.spreadsheet_id,
                body={"requests": [{"addSheet": {"properties": {"title": name}}}]},
            ).execute()
            self._refresh_sheet_ids()
        existing = self.read(name)
        if not existing:
            self.write_rows(name, 1, [headers])
            return
        extended, changed = extend_header_row(existing[0], headers)
        if changed:
            self.write_rows(name, 1, [extended])

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
        self._ensure_grid(name, end, width)
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
        self._api().spreadsheets().batchUpdate(
            spreadsheetId=self.spreadsheet_id,
            body={"requests": requests},
        ).execute()
        for request in requests:
            growth = request["appendDimension"]
            if growth["dimension"] == "ROWS":
                grid["rowCount"] += growth["length"]
            else:
                grid["columnCount"] += growth["length"]
        self._grids[name] = grid


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
    desk = {}
    try:
        desk = refresh_buying_desk(store, messages, batch_id)
    except Exception:
        logger.exception("Buying desk refresh failed")
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
            _rollback_catalog(store, batch_id)
            return {"status": "error", "processed": processed, "rollback": True}
        processed += 1
    return {"status": "success", "processed": processed}


def import_product_data(store, data: dict[str, Any]) -> dict[str, Any]:
    try:
        _fill_message_from_sheet(store, data)
        products = extract_products(data.get("content") or "", data.get("channel_username") or "")
        if not products:
            return {"success": True, "products_found": 0, "row": None}

        store.ensure("Offers", OFFER_HEADERS)
        store.ensure("Products", PRODUCT_HEADERS)
        offer_headers = _headers(store, "Offers", OFFER_HEADERS)
        existing_offers = _read_sheet_if_present(store, "Offers")
        imported_at = _now()
        offer_rows = []
        keys: list[tuple[str, str]] = []
        for index, product in enumerate(products):
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
            product["status"] = "needs_review" if qa["requires_review"] else "imported"
            if qa["requires_review"]:
                _log_issue(store, "WARN", data.get("id"), qa["reason"])
            offer_id = f"{data.get('batch_id') or 'offer'}:{data.get('id') or index}:{index}"
            offer_rows.append(_row_from_map(offer_headers, _offer_fields(product, data, offer_id, imported_at, qa.get("reason") or "")))
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
        existing = items_by_key.get(key, {})
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


def _item_key(channel: str, name: str) -> str:
    return f"{(channel or '').strip()}|{(name or '').strip().lower()}"


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
    target = (product_name or "").strip().lower()
    for index, row in enumerate(rows[1:], start=2):
        existing_name = row[name_idx] if name_idx < len(row) else ""
        existing_channel = row[channel_idx] if channel_idx < len(row) else ""
        if existing_name and str(existing_name).strip().lower() == target and existing_channel == channel_username:
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
    })


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
    products = store.read("Products")
    headers = _pad_headers(products[0] if products else PRODUCT_HEADERS)
    last_row = None
    deletions: list[int] = []
    for name, channel in keys:
        row_number = _find_product_row(products, headers, name, channel)
        matching = _offers_for_product(offers, name, channel)
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
        new_row = _product_row(headers, product, _message_from_offer(latest))
        _set_cell(new_row, headers, "Last Updated", latest.get("Import Timestamp") or "")
        if not existing:
            _set_cell(new_row, headers, "Import Timestamp", matching[0].get("Import Timestamp") or "")
        merged = _merge_current_row(existing, headers, new_row)
        if row_number:
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
    }


def _product_from_offers(offers: list[dict[str, Any]]) -> dict[str, Any]:
    latest = offers[-1]
    return {
        "name": latest.get("Product Name") or "",
        "variation_type": latest.get("Variation Type") or "",
        "sale_price": latest.get("Sale Price"),
        "actual_price": latest.get("Actual Price"),
        "price_type": latest.get("Price Type") or "",
        "price": latest.get("Price"),
        "consumer_price": latest.get("Consumer Price"),
        "currency": latest.get("Currency") or "",
        "packaging": latest.get("Packaging") or "",
        "volume": latest.get("Volume") or "",
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
        "offer_count": len(offers),
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
    target = (name or "").strip().lower()
    return [
        offer for offer in offers
        if str(offer.get("Product Name") or "").strip().lower() == target
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
