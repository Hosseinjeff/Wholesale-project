"""Push the current product sheet onto the WooCommerce shop.

Railway can reach the sheet and Telegram. The shop host publishes what this
service sends, then pulls photo bytes from /media/file without a bot token.
"""

from __future__ import annotations

import base64
import hashlib
import logging
import os
import threading
from typing import Any

import requests

from catalog.extract import minimum_purchase
from catalog.media import safe_file_id
from catalog.pricing import settings_from_rows
from catalog.sync import (
    GoogleSheetStore,
    MemoryStore,
    _positive_price,
    _product_key,
    _read_sheet_if_present,
    _records,
)

logger = logging.getLogger(__name__)

_SUPPLIERS = {
    "@bonakdarjavan": "بنکدار جوان",
    "@top_shop_rahimi": "تاپ‌شاپ رحیمی",
    "@nobelshop118": "نوبل",
}

_publish_lock = threading.Lock()


def _trim_name(value: Any) -> str:
    name = str(value or "").strip()
    return name.strip(" ،,")


def site_products(store: MemoryStore | GoogleSheetStore) -> list[dict[str, Any]] | None:
    """Sellable rows, plus held rows so the shop can hide them.

    None means the sheet could not be read. An empty list is a real empty sheet.
    """
    rows = _read_sheet_if_present(store, "Products")
    if not rows:
        return None
    _headers, records = _records(rows)
    products = []
    for record in records:
        status = str(record.get("Status") or "").strip().lower()
        held = status in {"skipped", "needs_review"}
        name = _trim_name(record.get("Product Name"))
        sale = _positive_price(record.get("Sale Price")) or 0
        if name == "" or (sale <= 0 and not held):
            continue
        channel = str(record.get("Channel Username") or "").strip()
        shelf = _positive_price(record.get("Consumer Price")) or _positive_price(record.get("Actual Price")) or 0
        minimum = _positive_price(record.get("Min Qty")) or minimum_purchase(
            str(record.get("Packaging") or ""),
            name,
        )
        products.append({
            "id": hashlib.sha256(f"{channel}|{name}".encode()).hexdigest()[:12],
            "name": name,
            "key": _product_key(name),
            "channel": channel,
            "supplier": _SUPPLIERS.get(channel, channel),
            "sale": int(sale),
            "shelf": int(shelf),
            "min": int(minimum),
            "image_file_id": safe_file_id(record.get("Image File ID")),
            "status": "hold" if held else "sell",
        })
    products.sort(key=lambda product: product["name"])
    return products


def site_settings(store: MemoryStore | GoogleSheetStore) -> dict[str, Any] | None:
    rows = _read_sheet_if_present(store, "Shop")
    if not rows:
        return None
    return settings_from_rows(rows).as_dict()


def publish_shop(
    store: MemoryStore | GoogleSheetStore,
    *,
    max_rounds: int = 8,
    images_only: bool = False,
) -> dict[str, Any]:
    """Send the catalog, then upload photo bytes until the queue is idle."""
    url = (os.getenv("SHOP_SITE_URL") or "").strip().rstrip("/")
    secret = os.getenv("SHOP_NOTIFY_SECRET") or ""
    if not url or not secret:
        return {"ok": False, "pushed": False, "reason": "unconfigured"}
    with _publish_lock:
        if images_only:
            result = _post_shop(url, secret, {"mode": "images", "token": secret})
            if not result.get("ok"):
                result["pushed"] = False
                return result
            products = []
        else:
            products = site_products(store)
            if products is None:
                return {"ok": False, "pushed": False, "reason": "sheet unread"}
            payload: dict[str, Any] = {"products": products, "token": secret}
            settings = site_settings(store)
            if settings:
                payload["settings"] = settings
            result = _post_shop(url, secret, payload)
            if not result.get("ok"):
                result["pushed"] = False
                return result
        pending = result.get("pending") if isinstance(result.get("pending"), list) else []
        rounds = 0
        while pending and rounds < max_rounds:
            item = pending[0] if isinstance(pending[0], dict) else {}
            photo = _telegram_photo(str(item.get("file") or ""))
            if photo is None:
                body = {"mode": "images", "token": secret, "skip": [item]}
            else:
                blob, mime = photo
                body = {
                    "mode": "images",
                    "token": secret,
                    "images": [{
                        "product": item.get("product"),
                        "file": item.get("file"),
                        "mime": mime,
                        "data": base64.b64encode(blob).decode("ascii"),
                    }],
                }
            nxt = _post_shop(url, secret, body)
            if not nxt.get("ok"):
                break
            result["attached"] = int(result.get("attached") or 0) + int(nxt.get("attached") or 0)
            result["images_left"] = int(nxt.get("images_left") or 0)
            pending = nxt.get("pending") if isinstance(nxt.get("pending"), list) else []
            rounds += 1
            if photo is not None and int(nxt.get("attached") or 0) <= 0:
                break
        result["pushed"] = True
        result["count"] = len(products)
        logger.info(
            "Shop publish created=%s updated=%s hidden=%s images_left=%s",
            result.get("created"),
            result.get("updated"),
            result.get("hidden"),
            result.get("images_left"),
        )
        return result


def _telegram_photo(file_id: str) -> tuple[bytes, str] | None:
    """Fetch one Telegram photo. The shop receives the bytes from us."""
    token = os.getenv("TELEGRAM_BOT_TOKEN") or ""
    file_id = safe_file_id(file_id)
    if not token or not file_id:
        return None
    try:
        info = requests.get(
            f"https://api.telegram.org/bot{token}/getFile",
            params={"file_id": file_id},
            timeout=15,
        )
        path = ""
        if info.status_code == 200:
            path = str(((info.json().get("result") or {}).get("file_path") or ""))
        if not path or ".." in path or path.startswith("/"):
            return None
        upstream = requests.get(f"https://api.telegram.org/file/bot{token}/{path}", timeout=25)
    except requests.RequestException:
        return None
    if upstream.status_code != 200 or not upstream.content or len(upstream.content) > 4_000_000:
        return None
    mime = "image/jpeg"
    lower = path.lower()
    if lower.endswith(".png"):
        mime = "image/png"
    elif lower.endswith(".webp"):
        mime = "image/webp"
    return upstream.content, mime


def _post_shop(url: str, secret: str, payload: dict[str, Any]) -> dict[str, Any]:
    try:
        response = requests.post(
            f"{url}/?molavi-sync=1",
            json=payload,
            headers={
                "Content-Type": "application/json",
                "X-Shop-Token": secret,
                "User-Agent": "MolaviShop/1",
            },
            timeout=90,
        )
    except requests.RequestException as exc:
        logger.warning("Shop publish request failed: %s", exc.__class__.__name__)
        return {"ok": False, "error": "request failed"}
    try:
        body = response.json()
    except ValueError:
        body = {}
    if not isinstance(body, dict):
        body = {}
    if response.status_code >= 400 or not body.get("ok"):
        logger.warning("Shop publish rejected: %s", response.status_code)
        return {"ok": False, "status": response.status_code, "error": str(body.get("error") or "")[:120]}
    return body
