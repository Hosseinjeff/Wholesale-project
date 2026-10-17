"""Short store, order, and product reports for the bot."""

from __future__ import annotations

from typing import Any

from catalog.extract import minimum_purchase
from catalog.orders import ORDER_STATUSES, _STATUS_FA

_COMMANDS = {
    "/store": "store",
    "/status": "store",
    "/shop": "store",
    "/orders": "orders",
    "/order": "orders",
    "/products": "products",
    "/product": "products",
    "/start": "help",
    "/help": "help",
    "/فروشگاه": "store",
    "/سفارش": "orders",
    "/سفارشها": "orders",
    "/کالا": "products",
    "/کالاها": "products",
}

_PHRASES = {
    "وضعیت فروشگاه": "store",
    "وضعیت سفارش": "orders",
    "وضعیت سفارشها": "orders",
    "وضعیت کالا": "products",
    "وضعیت کالاها": "products",
}


def _fold(text: str) -> str:
    return (
        text.replace("\u200c", "")
        .replace("ي", "ی")
        .replace("ك", "ک")
        .replace("‌", "")
        .strip()
    )


def command_name(text: str) -> str | None:
    """A store command, or None when the text is a supplier post."""
    raw = (text or "").strip()
    if not raw:
        return None
    if raw.startswith("/"):
        token = raw.split()[0].split("@", 1)[0].lower()
        token = _fold(token)
        return _COMMANDS.get(token) or _COMMANDS.get(token.replace("‌", ""))
    phrase = _fold(raw)
    return _PHRASES.get(phrase)


def _read(store: Any, name: str) -> list[list[Any]]:
    try:
        rows = store.read(name) or []
    except Exception:
        return []
    return rows if isinstance(rows, list) else []


def _records(rows: list[list[Any]]) -> list[dict[str, Any]]:
    if len(rows) < 2:
        return []
    headers = [str(header) for header in rows[0]]
    records = []
    for row in rows[1:]:
        if not any(str(cell or "").strip() for cell in row):
            continue
        records.append({
            header: row[index] if index < len(row) else ""
            for index, header in enumerate(headers)
        })
    return records


def _whole(value: Any) -> int:
    try:
        return int(round(float(str(value).replace(",", "") or 0)))
    except (TypeError, ValueError):
        return 0


def _toman(amount: int) -> str:
    return f"{amount:,} تومان"


def _orders(store: Any) -> list[dict[str, Any]]:
    return _records(_read(store, "Orders"))


def _products(store: Any) -> list[dict[str, Any]]:
    return [
        row for row in _records(_read(store, "Products"))
        if str(row.get("Product Name") or "").strip()
    ]


def _by_status(orders: list[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    grouped = {status: [] for status in ORDER_STATUSES}
    for order in orders:
        status = str(order.get("Status") or "new")
        grouped.setdefault(status, []).append(order)
    return grouped


def store_status(store: Any) -> str:
    products = _products(store)
    orders = _orders(store)
    grouped = _by_status(orders)
    with_photo = sum(1 for product in products if str(product.get("Image File ID") or "").strip())
    open_total = sum(
        _whole(order.get("Total"))
        for order in orders
        if str(order.get("Status") or "") != "cancelled"
    )
    lines = [
        "فروشگاه",
        f"کالاها: {len(products)}",
        f"کالاهای دارای تصویر پست: {with_photo}",
        "سفارش‌ها: {count} (جدید {new}، بررسی {review}، تأیید {confirmed}، لغو {cancelled})".format(
            count=len(orders),
            new=len(grouped.get("new", [])),
            review=len(grouped.get("review", [])),
            confirmed=len(grouped.get("confirmed", [])),
            cancelled=len(grouped.get("cancelled", [])),
        ),
        f"جمع سفارش‌های باز: {_toman(open_total)}",
    ]
    if orders:
        latest = orders[-1]
        lines.append(
            "آخرین سفارش: {id} — {name} — {total}".format(
                id=latest.get("Order ID") or "",
                name=latest.get("Customer Name") or "",
                total=_toman(_whole(latest.get("Total"))),
            )
        )
    else:
        lines.append("هنوز سفارشی در برگه نیست.")
    return "\n".join(lines)


def orders_status(store: Any) -> str:
    orders = _orders(store)
    if not orders:
        return "سفارش‌ها\nهنوز سفارشی در برگه نیست."
    grouped = _by_status(orders)
    lines = ["سفارش‌ها"]
    for status in ORDER_STATUSES:
        bucket = grouped.get(status, [])
        total = sum(_whole(order.get("Total")) for order in bucket)
        lines.append(f"{_STATUS_FA.get(status, status)}: {len(bucket)} — {_toman(total)}")
    lines.append("آخرین‌ها:")
    for order in orders[-5:]:
        lines.append(
            "— {id} | {name} | {total} | {status}".format(
                id=order.get("Order ID") or "",
                name=order.get("Customer Name") or "",
                total=_toman(_whole(order.get("Total"))),
                status=_STATUS_FA.get(str(order.get("Status") or ""), str(order.get("Status") or "")),
            )
        )
    return "\n".join(lines)


def products_status(store: Any) -> str:
    products = _products(store)
    if not products:
        return "کالاها\nبرگه کالاها خالی است."
    photos = 0
    shell = 0
    single = 0
    review = 0
    categories: dict[str, int] = {}
    for product in products:
        if str(product.get("Image File ID") or "").strip():
            photos += 1
        if str(product.get("Status") or "").strip().lower() == "needs_review":
            review += 1
        minimum = _whole(product.get("Min Qty")) or minimum_purchase(
            str(product.get("Packaging") or ""),
            str(product.get("Product Name") or ""),
        )
        if minimum >= 6:
            shell += 1
        else:
            single += 1
        category = str(product.get("Category") or "").strip() or "بدون دسته"
        categories[category] = categories.get(category, 0) + 1
    lines = [
        "کالاها",
        f"تعداد: {len(products)}",
        f"دارای تصویر پست: {photos}",
        f"بدون تصویر پست: {len(products) - photos}",
        f"حداقل خرید ۶: {shell}",
        f"حداقل خرید ۱: {single}",
        f"نیاز به بررسی: {review}",
        "دسته‌ها:",
    ]
    for category, count in sorted(categories.items(), key=lambda item: (-item[1], item[0]))[:12]:
        lines.append(f"— {category}: {count}")
    return "\n".join(lines)


def help_status() -> str:
    return "\n".join([
        "دستورهای فروشگاه",
        "/store — وضعیت کلی",
        "/orders — وضعیت سفارش‌ها",
        "/products — وضعیت کالاها",
    ])


def reply_for_command(command: str, store: Any | None) -> str:
    if command == "help":
        return help_status()
    if store is None:
        return "برگه هنوز وصل نیست."
    if command == "store":
        return store_status(store)
    if command == "orders":
        return orders_status(store)
    if command == "products":
        return products_status(store)
    return help_status()


def private_chat(data: dict[str, Any]) -> bool:
    kind = str(data.get("chat_type") or "")
    return kind not in {"channel", "group", "supergroup"} and bool(data.get("chat_id"))
