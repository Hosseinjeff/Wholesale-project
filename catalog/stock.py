"""Source-backed supplier availability labels for the product pipeline."""

from __future__ import annotations

import re
import unicodedata


_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")


def _text(value: object) -> str:
    text = str(value or "").translate(_DIGITS).replace("ي", "ی").replace("ك", "ک")
    text = re.sub(r"[\u200b-\u200f\ufeffـ]", "", text)
    text = "".join(char for char in text if unicodedata.category(char) != "Mn")
    return re.sub(r"\s+", " ", text).strip().lower()


def _source_text(value: object) -> str:
    raw = str(value or "").translate(_DIGITS).replace("ي", "ی").replace("ك", "ک")
    raw = re.sub(r"[\u200b-\u200f\ufeffـ]", "", raw)
    raw = "".join(char for char in raw if unicodedata.category(char) != "Mn")
    return "\n".join(re.sub(r"\s+", " ", line).strip().lower() for line in raw.splitlines())


def normalize_stock_status(value: object) -> str:
    """Normalize a product-scoped stock label without inferring from omission."""
    text = _text(value)
    if not text:
        return "Unknown"
    if text in {"out of stock", "outofstock", "unavailable", "sold out", "not available", "not in stock", "ناموجود", "تمام شد", "تمام شده"}:
        return "Out of Stock"
    if text in {"available", "in stock", "instock", "موجود", "موجود است", "موجوده", "قابل سفارش"}:
        return "Available"
    return _explicit_stock_status(text)


def explicit_stock_status(source: object) -> str:
    """Recognize clear supplier statements; ambiguous wording remains unknown."""
    return _explicit_stock_status(_source_text(source))


def _explicit_stock_status(text: str) -> str:
    if not text:
        return "Unknown"
    # A double negative such as «ناموجود نیست» does not establish the stock level.
    if re.search(r"ناموجود\s+نیست|موجود\s+نیست\s+نیست", text):
        return "Unknown"
    out_of_stock = re.search(
        r"(?:\b(?:out\s+of\s+stock|sold\s+out|unavailable|not\s+available|not\s+in\s+stock)\b"
        r"|ناموجود|اتمام\s+موجودی|موجودی\s+(?:تمام|به\s+پایان)\s*(?:شد|شده|رسید|رسیده)"
        r"|موجود\s+(?:نیست|نمی\s*باشد|نداریم)|فعلاً?\s+(?:موجود\s+)?نداریم"
        r"|(?:^|[\n،؛.!])\s*تمام\s+شد(?:ه)?\s*(?:$|[\n،؛.!]))",
        text,
        re.I,
    )
    if out_of_stock:
        return "Out of Stock"
    available = re.search(
        r"(?:\b(?:in\s+stock|available)\b|موجود\s+(?:است|می\s*باشد|داریم|شد|شده|هست)|(?<![آ-ی])موجود(?!ی)|قابل\s+(?:خرید|سفارش))",
        text,
        re.I,
    )
    return "Available" if available else "Unknown"
