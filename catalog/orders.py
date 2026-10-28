"""Shop orders on the same spreadsheet as the price book.

Orders is one row per order, the way Products is one row per product.
OrderLines is one row per line, the way Offers is one row per price.
A later status update rewrites the order row and replaces its lines.
"""

from __future__ import annotations

import os
from typing import Any

ORDER_HEADERS = [
    "Order ID",
    "Created At",
    "Updated At",
    "Customer Name",
    "Customer Username",
    "Phone",
    "Email",
    "Address",
    "Note",
    "Progress",
    "Total",
    "Currency",
    "Status",
    "Woo Status",
    "Payment Status",
    "Payment Method",
    "Transaction ID",
    "Paid At",
    "Line Count",
]

ORDER_LINE_HEADERS = [
    "Order ID",
    "Line",
    "Created At",
    "Product ID",
    "Product Name",
    "Packaging",
    "Volume",
    "Supplier",
    "SKU",
    "Supplier Offer ID",
    "Quantity",
    "Min Qty",
    "Sale Price",
    "Consumer Price",
    "Unit Price",
    "Line Total",
    "Currency",
]

OPERATOR_HEADERS = [
    "Chat ID",
    "Username",
    "Name",
    "Last Seen",
]

ORDER_STATUSES = ("new", "review", "confirmed", "cancelled")

_STATUS_FA = {
    "new": "جدید",
    "review": "در حال بررسی",
    "confirmed": "تأیید شده",
    "cancelled": "لغو شده",
}


def _headers_of(store: Any, name: str) -> list[str]:
    rows = store.read(name) or []
    if rows and rows[0]:
        return [str(header) for header in rows[0]]
    return []


def _row(headers: list[str], record: dict[str, Any]) -> list[Any]:
    return [record.get(header, "") if record.get(header) is not None else "" for header in headers]


def _find_row(rows: list[list[Any]], header: str, value: str) -> int | None:
    if not rows:
        return None
    headers = [str(item) for item in rows[0]]
    if header not in headers:
        return None
    index = headers.index(header)
    for row_number, row in enumerate(rows[1:], start=2):
        cell = row[index] if index < len(row) else ""
        if str(cell or "") == value:
            return row_number
    return None


def _delete_matching(store: Any, sheet: str, header: str, value: str) -> None:
    rows = store.read(sheet) or []
    if len(rows) < 2:
        return
    headers = [str(item) for item in rows[0]]
    if header not in headers:
        return
    index = headers.index(header)
    matches = [
        row_number
        for row_number, row in enumerate(rows, start=1)
        if row_number > 1 and index < len(row) and str(row[index] or "") == value
    ]
    if matches:
        store.delete_rows(sheet, matches)


def _text(value: Any, limit: int = 500) -> str:
    return str(value or "").strip()[:limit]


def _whole(value: Any) -> int:
    try:
        return int(round(float(value)))
    except (TypeError, ValueError):
        return 0


def normalize_order(order: dict[str, Any]) -> dict[str, Any]:
    lines_in = order.get("lines") if isinstance(order.get("lines"), list) else []
    lines = []
    for raw in lines_in:
        if not isinstance(raw, dict):
            continue
        lines.append({
            "id": _text(raw.get("id") or raw.get("product_id"), 40),
            "name": _text(raw.get("name"), 200),
            "packaging": _text(raw.get("packaging"), 120),
            "volume": _text(raw.get("volume"), 80),
            "supplier": _text(raw.get("supplier"), 80),
            "sku": _text(raw.get("sku") or raw.get("id") or raw.get("product_id"), 80),
            "supplier_offer_id": _text(raw.get("supplier_offer_id"), 120),
            "qty": max(0, _whole(raw.get("qty") or raw.get("quantity"))),
            "min": max(0, _whole(raw.get("min") or raw.get("min_qty"))),
            "sale": _whole(raw.get("sale")),
            "shelf": _whole(raw.get("shelf") or raw.get("consumer")),
            "unit": _whole(raw.get("unit")),
            "total": _whole(raw.get("total")),
        })
    status = _text(order.get("status") or "new", 20)
    if status not in ORDER_STATUSES:
        status = "new"
    payment_status = _text(order.get("payment_status") or "pending", 20).lower()
    if payment_status not in {"pending", "verified", "failed", "refunded", "unknown"}:
        payment_status = "unknown"
    woo_status = _text(order.get("woo_status"), 32).lower()
    if woo_status and not all(char.isalnum() or char in "-_" for char in woo_status):
        woo_status = "unknown"
    created = _text(order.get("at") or order.get("created_at"), 40)
    return {
        "id": _text(order.get("id"), 40),
        "at": created,
        "name": _text(order.get("name"), 80),
        "username": _text(order.get("username"), 40),
        "phone": _text(order.get("phone"), 20),
        "email": _text(order.get("email"), 120),
        "address": _text(order.get("address"), 300),
        "note": _text(order.get("note"), 500),
        "progress": order.get("progress") if isinstance(order.get("progress"), (int, float)) else 0,
        "total": _whole(order.get("total")),
        "status": status,
        "woo_status": woo_status,
        "payment_status": payment_status,
        "payment_method": _text(order.get("payment_method"), 80),
        "transaction_id": _text(order.get("transaction_id"), 120),
        "paid_at": _text(order.get("paid_at"), 40),
        "lines": [line for line in lines if line["name"] and line["qty"] > 0],
    }


def _order_record(order: dict[str, Any], updated_at: str) -> dict[str, Any]:
    return {
        "Order ID": order["id"],
        "Created At": order["at"],
        "Updated At": updated_at or order["at"],
        "Customer Name": order["name"],
        "Customer Username": order["username"],
        "Phone": order["phone"],
        "Email": order["email"],
        "Address": order["address"],
        "Note": order["note"],
        "Progress": order["progress"],
        "Total": order["total"],
        "Currency": "IRT",
        "Status": order["status"],
        "Woo Status": order["woo_status"],
        "Payment Status": order["payment_status"],
        "Payment Method": order["payment_method"],
        "Transaction ID": order["transaction_id"],
        "Paid At": order["paid_at"],
        "Line Count": len(order["lines"]),
    }


def _line_record(order: dict[str, Any], line: dict[str, Any], number: int) -> dict[str, Any]:
    return {
        "Order ID": order["id"],
        "Line": number,
        "Created At": order["at"],
        "Product ID": line["id"],
        "Product Name": line["name"],
        "Packaging": line["packaging"],
        "Volume": line["volume"],
        "Supplier": line["supplier"],
        "SKU": line["sku"],
        "Supplier Offer ID": line["supplier_offer_id"],
        "Quantity": line["qty"],
        "Min Qty": line["min"],
        "Sale Price": line["sale"],
        "Consumer Price": line["shelf"],
        "Unit Price": line["unit"],
        "Line Total": line["total"],
        "Currency": "IRT",
    }


def upsert_shop_order(store: Any, order: dict[str, Any], updated_at: str = "") -> dict[str, Any]:
    """Insert or replace one order and its lines."""
    normalized = normalize_order(order)
    if not normalized["id"]:
        raise ValueError("missing order id")
    store.ensure("Orders", ORDER_HEADERS)
    store.ensure("OrderLines", ORDER_LINE_HEADERS)
    order_headers = _headers_of(store, "Orders")
    line_headers = _headers_of(store, "OrderLines")
    existing = store.read("Orders") or []
    row_number = _find_row(existing, "Order ID", normalized["id"])
    row = _row(order_headers, _order_record(normalized, updated_at))
    if row_number:
        store.write_rows("Orders", row_number, [row])
    else:
        store.append("Orders", [row])
    _delete_matching(store, "OrderLines", "Order ID", normalized["id"])
    line_rows = [
        _row(line_headers, _line_record(normalized, line, index))
        for index, line in enumerate(normalized["lines"], start=1)
    ]
    if line_rows:
        store.append("OrderLines", line_rows)
    return {"written": True, "order_id": normalized["id"], "lines": len(line_rows)}


def remember_operator(store: Any, chat_id: Any, username: str = "", name: str = "", seen_at: str = "") -> None:
    """Keep the private chat that talks to the bot, so order notices have a destination."""
    chat = str(chat_id or "").strip()
    if not chat:
        return
    store.ensure("Operators", OPERATOR_HEADERS)
    headers = _headers_of(store, "Operators")
    rows = store.read("Operators") or []
    record = {
        "Chat ID": chat,
        "Username": _text(username, 80),
        "Name": _text(name, 80),
        "Last Seen": seen_at,
    }
    row_number = _find_row(rows, "Chat ID", chat)
    if row_number:
        current = rows[row_number - 1]
        previous = {
            header: current[index] if index < len(current) else ""
            for index, header in enumerate(headers)
        }
        if not record["Username"]:
            record["Username"] = previous.get("Username") or ""
        if not record["Name"]:
            record["Name"] = previous.get("Name") or ""
        store.write_rows("Operators", row_number, [_row(headers, record)])
        return
    store.append("Operators", [_row(headers, record)])


def operator_chat_ids(store: Any | None = None) -> list[str]:
    """Chats that should hear about a new order."""
    found: list[str] = []
    extra = os.getenv("ORDER_NOTIFY_CHAT_ID") or ""
    for part in extra.split(","):
        chat = part.strip()
        if chat and chat not in found:
            found.append(chat)
    if store is None:
        return found
    try:
        rows = store.read("Operators") or []
    except Exception:
        return found
    if len(rows) < 2:
        return found
    headers = [str(item) for item in rows[0]]
    if "Chat ID" not in headers:
        return found
    index = headers.index("Chat ID")
    for row in rows[1:]:
        chat = str(row[index] if index < len(row) else "").strip()
        if chat and chat not in found:
            found.append(chat)
    return found


def _toman(amount: int) -> str:
    return f"{amount:,} تومان"


def format_order_notice(order: dict[str, Any]) -> str:
    """Persian notice with the same facts the order sheet stores."""
    normalized = normalize_order(order)
    lines = [
        "سفارش جدید",
        f"شماره: {normalized['id']}",
        f"مشتری: {normalized['name']}",
    ]
    if normalized["username"]:
        lines.append(f"حساب: {normalized['username']}")
    if normalized["phone"]:
        lines.append(f"تلفن: {normalized['phone']}")
    if normalized["email"]:
        lines.append(f"ایمیل: {normalized['email']}")
    if normalized["address"]:
        lines.append(f"نشانی: {normalized['address']}")
    lines.append(f"جمع: {_toman(normalized['total'])}")
    lines.append(f"وضعیت: {_STATUS_FA.get(normalized['status'], normalized['status'])}")
    if normalized["lines"]:
        lines.append("اقلام:")
        for line in normalized["lines"][:30]:
            detail = f"— {line['name']} × {line['qty']}"
            if line["packaging"]:
                detail += f"، {line['packaging']}"
            if line["min"]:
                detail += f"، حداقل {line['min']}"
            detail += f" — {_toman(line['total'])}"
            lines.append(detail)
    if normalized["note"]:
        lines.append(f"یادداشت: {normalized['note']}")
    return "\n".join(lines)
