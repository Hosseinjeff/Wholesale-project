"""Product extraction mirrored from the Apps Script catalog writer.

Channel dispatch matches google_apps_script.js: exact @username, then the
universal line parser. JavaScript \\b is ASCII-only, so those patterns use
re.ASCII.
"""

from __future__ import annotations

import re
from typing import Any

PERSIAN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789")

MESSAGE_HEADERS = [
    "ID",
    "Channel",
    "Channel Username",
    "Author",
    "Content",
    "Timestamp",
    "URL",
    "Forwarded By",
    "Forwarded At",
    "Has Media",
    "Media Type",
    "Import Timestamp",
    "Status",
    "Batch ID",
]

PRODUCT_HEADERS = [
    "Channel ID",
    "Product ID",
    "Product Name",
    "Variation Type",
    "Sale Price",
    "Actual Price",
    "Price Type",
    "Price",
    "Currency",
    "Consumer Price",
    "Double Pack Price",
    "Double Pack Consumer Price",
    "Packaging",
    "Volume",
    "Category",
    "Description",
    "Stock Status",
    "Location",
    "Contact Info",
    "Original Message",
    "Channel",
    "Channel Username",
    "Message Timestamp",
    "Forwarded By",
    "Import Timestamp",
    "Last Updated",
    "Extraction Confidence Score",
    "Confidence",
    "Status",
    "Batch ID",
    "Previous Sale Price",
    "Previous Consumer Price",
    "Price Changed At",
    "Latest Offer ID",
    "Offer Count",
]

OFFER_HEADERS = [
    "Offer ID",
    "Product Name",
    "Channel Username",
    "Channel",
    "Sale Price",
    "Consumer Price",
    "Price",
    "Actual Price",
    "Price Type",
    "Currency",
    "Packaging",
    "Volume",
    "Category",
    "Variation Type",
    "Stock Status",
    "Description",
    "Status",
    "Review Reason",
    "Extraction Confidence Score",
    "Confidence",
    "Message ID",
    "Batch ID",
    "Message Timestamp",
    "Forwarded By",
    "Import Timestamp",
    "Original Message",
]

BATCH_STATUS_HEADERS = [
    "Timestamp",
    "Batch ID",
    "Expected Messages",
    "Written Messages",
    "Processed Messages",
    "Extraction Status",
    "Rollback",
]

EXECUTION_LOG_HEADERS = [
    "Timestamp",
    "Action",
    "Level",
    "Channel",
    "Content Length",
    "Message",
    "Error Code",
    "Details",
]

_NAME_EMOJI = re.compile(r"[✅❌🛑⭕️🚀🔥💎📦✨🌟📣💰🛍️•●▪]")
_NAME_LABELS = [
    "قیمت فروش ما",
    "قیمت مصرف کننده",
    "قیمت مصرف",
    "قیمت",
    "قیمت هر یک ورق",
    "قیمت هر ورق",
    "قیمت هر عدد",
    "قیمت هر شیشه",
    "فی",
    "تعداد",
    "باکس",
    "کارتن",
    "دونه ای",
    "مصرف",
    "موجود",
    "خرید",
]
_NAME_SPLIT = re.compile(r"(?:قیمت|تعداد|باکس|کارتن|دونه ای|مصرف|فی|موجود|✅|🚀|🔥|💎|📦|✨|🌟|📣|💰|🛍️)", re.I)
_CONTACT_NAME = re.compile(r"(آدرس|میدان|خیابان|پاساژ|پلاک|بازار|wa\.me|https?://|@|واتساپ|تماس|اتمام|ناموجود|تمام شد)", re.I)
_SALE_LINE = re.compile(
    r"(?:فروش|خرید|\bما\b|همکار|دونه\s*ای|فی|هر\s*عدد|یک\s*باکس|قیمت\s*باکس)",
    re.I | re.ASCII,
)
_PRICE_TOKEN = re.compile(r"[\d,/\.]{4,}")
_STANDALONE_PRICE = re.compile(r"^\s*[\d,/]{4,}\s*(?:تومان|ت|ریال)?\s*$")
_PACK_HINT = re.compile(r"(?:باکس|کارتن|شیرینگ|ورق)", re.I)


def persian_to_english_numbers(text: str) -> str:
    if not text:
        return ""
    return str(text).translate(PERSIAN_DIGITS)


def normalize(text: str) -> str:
    if not text:
        return ""
    result = persian_to_english_numbers(text)
    result = re.sub(r"[\u200B-\u200D\uFEFF]", "", result)
    result = re.sub(r"\s+", " ", result)
    return result.strip()


def parse_price(price_str: Any) -> int:
    if price_str is None or price_str == "":
        return 0
    clean = persian_to_english_numbers(str(price_str))
    clean = re.sub(r"[/,٫\u066B]", "", clean)
    clean = clean.replace(".", "")
    clean = re.sub(r"\D", "", clean)
    return int(clean) if clean else 0


def classify_message(content: str, channel_username: str) -> dict[str, Any]:
    processed = normalize(content or "")
    is_oos = re.search(r"(?:تمام شد|ناموجود|🚫)", content or "", re.I)
    if is_oos and not re.search(r"(?:قیمت|تومان)", content or "", re.I):
        return {"type": "out_of_stock", "confidence": 0.9}

    has_pricing = re.search(r"(?:قیمت|تومان|تومن|ت|ریال|rial|:|[\d]+/[\d]+)", processed, re.I)
    has_numbers = re.search(r"\d+", processed)
    if has_pricing and has_numbers:
        normalized_channel = (channel_username or "").lower()
        if any(name in normalized_channel for name in ("bonakdarjavan", "nobelshop118", "top_shop_rahimi")):
            return {"type": "product_listing", "confidence": 0.95}
        return {"type": "product_listing", "confidence": 0.7}
    return {"type": "non_product", "confidence": 0.8}


def clean_product_name(raw: str) -> str:
    if not raw:
        return ""
    clean = _NAME_EMOJI.sub("", raw)
    clean = re.sub(r"^(?:نام)?\s*محصول[:\s]*", "", clean)
    clean = re.sub(r"^[-+*○◦‣▪■□➔➢➤]+", "", clean)
    clean = re.sub(r"[:]+$", "", clean).strip()

    lower_clean = clean.lower()
    if any(lower_clean == label or lower_clean.startswith(label + ":") or lower_clean.startswith(label + " ") for label in _NAME_LABELS):
        if re.search(r"[\d۰-۹]", clean) or "٫" in clean or "/" in clean:
            return ""

    if _NAME_SPLIT.search(clean):
        parts = _NAME_SPLIT.split(clean)
        if parts and len(parts[0].strip()) > 2:
            return re.sub(r"[:\s-]+$", "", parts[0].strip()).strip()

    if _CONTACT_NAME.search(clean):
        return ""
    if not re.search(r"[\u0600-\u06FFA-Za-z]", clean):
        return ""
    if re.match(r"^\s*[\d۰-۹\-\.,/\s]+$", clean):
        return ""
    return clean


def extract_packaging(text: str) -> str:
    match = re.search(r"(?:باکس|کارتن|شیرینگ)\s*(\d+\s*(?:عددی|تایی|عدد))", text or "", re.I)
    return match.group(0) if match else ""


def extract_volume(text: str) -> str:
    match = re.search(r"(\d+\s*(?:گرم|gr|ml|لیتر|میلی))", text or "", re.I)
    return match.group(0) if match else ""


def infer_price_type(packaging: str) -> str:
    if not packaging:
        return "single"
    if re.search(r"باکس|کارتن|شیرینگ|ورق", packaging, re.I):
        return "pack"
    return "single"


def extract_category(name: str, description: str, channel_username: str) -> str:
    text = f"{name or ''} {description or ''}".lower()
    channel_hints = {
        "@wholesale_electronics": ["electronics"],
        "@fashion_wholesale": ["clothing", "fashion"],
        "@beauty_wholesale": ["beauty", "cosmetics"],
        "@home_decor": ["home", "furniture"],
        "@bonakdarjavan": ["food", "canned", "conserves", "کنسرو"],
        "@top_shop_rahimi": ["beverages", "drinks", "energy", "نوشیدنی", "انرژی"],
        "@nobelshop118": ["beverages", "coffee", "cappuccino", "نوشیدنی", "قهوه"],
    }
    hints = channel_hints.get(channel_username or "")
    if hints and (hints[0] in text or any(keyword in text for keyword in hints)):
        return hints[0][:1].upper() + hints[0][1:]

    persian_categories = {
        "food": ["کنسرو", "خوراک", "غذا", "میوه", "سبزی", "لبنیات"],
        "beverages": ["نوشیدنی", "قهوه", "چای", "انرژی", "مایع", "جوشان"],
        "electronics": ["گوشی", "موبایل", "لپ تاپ", "تبلت", "شارژر", "هدفون"],
        "clothing": ["لباس", "شلوار", "پیراهن", "کفش", "کلاه", "تیشرت"],
        "home": ["خانه", "دکور", "مبلمان", "آشپزخانه", "حمام", "رختخواب"],
        "beauty": ["آرایشی", "پوست", "مو", "کرم", "لوسیون", "ماسک"],
    }
    for category, keywords in persian_categories.items():
        if any(keyword in text for keyword in keywords):
            return category[:1].upper() + category[1:]

    categories = {
        "electronics": ["phone", "iphone", "samsung", "laptop", "computer", "tablet", "charger", "cable", "headphone", "airpods", "macbook", "ipad"],
        "clothing": ["shirt", "pants", "dress", "jacket", "shoe", "boot", "hat", "jeans", "t-shirt", "hoodie"],
        "food": ["canned", "food", "conserves", "fruit", "vegetable", "dairy"],
        "beverages": ["drink", "coffee", "tea", "energy", "beverage", "cappuccino"],
        "home": ["furniture", "decoration", "kitchen", "bathroom", "bedding", "sofa", "table", "chair"],
        "beauty": ["cosmetic", "skincare", "makeup", "perfume", "hair", "cream", "lotion", "mask"],
        "sports": ["equipment", "fitness", "sport", "gym", "workout", "bicycle", "ball", "racket"],
        "automotive": ["car", "auto", "vehicle", "tire", "part", "engine", "wheel"],
    }
    for category, keywords in categories.items():
        if any(keyword in text for keyword in keywords):
            return category[:1].upper() + category[1:]
    return "General"


def extract_prices_from_line(line: str) -> dict[str, int]:
    result = {"sale": 0, "consumer": 0}
    clean_line = persian_to_english_numbers(line or "")
    clean_line = re.sub(r"(\d+)/(\d+)", r"\1\2", clean_line)
    clean_line = re.sub(r"[,\.\u066B]", "", clean_line)
    numbers = re.findall(r"\d+", clean_line)
    vals = [int(n) for n in numbers if 4 <= len(n) <= 8 and int(n) > 1000]
    if re.search(r"(?:مصرف|روی جلد)", line or ""):
        result["consumer"] = vals[0] if vals else 0
    elif re.search(r"(?:فروش|خرید|ما|همکار)", line or ""):
        result["sale"] = vals[0] if vals else 0
    else:
        result["sale"] = vals[0] if vals else 0
    if len(vals) >= 2:
        result["sale"] = min(vals)
        result["consumer"] = max(vals)
    return result


def finalize_product(product: dict[str, Any], channel: str) -> dict[str, Any]:
    channel_key = (channel or "").lower()
    sale_price = product.get("sale_price") or product.get("price") or 0
    consumer_price = product.get("actual_price") or product.get("consumer_price") or None
    if not consumer_price and "nobelshop118" in channel_key and sale_price > 0:
        consumer_price = sale_price
    packaging = product.get("packaging") or ""
    return {
        "name": product.get("name") or "",
        "sale_price": sale_price,
        "actual_price": consumer_price,
        "price_type": product.get("price_type") or infer_price_type(packaging),
        "price": sale_price,
        "consumer_price": consumer_price,
        "currency": "IRT",
        "packaging": packaging,
        "volume": product.get("volume") or "",
        "stock_status": product.get("stock_status") or "Available",
        "variation_type": product.get("variation_type") or "",
        "channel_username": channel,
        "description": product.get("description") or "",
        "category": extract_category(product.get("name") or "", product.get("description") or "", channel),
        "confidence": product.get("confidence") or 0.95,
        "extraction_confidence": product.get("extraction_confidence") or product.get("confidence") or 0.95,
    }


def analyze_pricing(lines: list[str]) -> dict[str, Any]:
    sale_price = 0
    actual_price = 0
    price_type = "unit"
    extraction_confidence = 0
    for line in lines:
        norm = normalize(line)
        if re.search(r"(?:مصرف|consumer)", line, re.I):
            match = _PRICE_TOKEN.search(norm)
            if match:
                actual_price = parse_price(match.group(0))
        if _SALE_LINE.search(line):
            match = _PRICE_TOKEN.search(norm)
            if match:
                sale_price = parse_price(match.group(0))
                extraction_confidence = 0.9
        elif not sale_price:
            match = _STANDALONE_PRICE.search(norm)
            if match:
                sale_price = parse_price(match.group(0))
                extraction_confidence = 0.6
        if _PACK_HINT.search(line):
            price_type = "pack"
    return {
        "sale_price": sale_price,
        "actual_price": actual_price,
        "price_type": price_type,
        "extraction_confidence": extraction_confidence,
    }


def extract_channel_bonakdarjavan(content: str) -> list[dict[str, Any]]:
    lines = [line.strip() for line in (content or "").split("\n") if line.strip()]
    product_lines: list[str] = []
    for line in lines:
        if re.match(r"^(?:ثبت سفارش|پاسخگو|آدرس|wa\.me|https?://|👇)", line):
            break
        product_lines.append(line)
    if len(product_lines) < 2:
        return []

    name = clean_product_name(product_lines[0])
    if not name or len(name) < 3:
        return []

    pricing = analyze_pricing(product_lines)
    packaging = ""
    for line in product_lines:
        if re.search(r"(?:باکس|کارتن|تعداد)", line) and "قیمت" not in line:
            packaging = line

    if pricing["sale_price"] > 0 or pricing["actual_price"] > 0:
        confidence = pricing["extraction_confidence"] or 0.8
        return [{
            "name": name,
            "sale_price": pricing["sale_price"],
            "consumer_price": pricing["actual_price"],
            "price": pricing["sale_price"] if pricing["sale_price"] > 0 else pricing["actual_price"],
            "actual_price": pricing["actual_price"],
            "packaging": packaging,
            "channel": "@bonakdarjavan",
            "currency": "IRT",
            "stock_status": "Available",
            "description": content,
            "confidence": confidence,
            "extraction_confidence": confidence,
            "price_type": pricing["price_type"],
        }]
    return []


def extract_channel_top_shop_rahimi(content: str) -> list[dict[str, Any]]:
    flat = (content or "").replace("\n", " ")
    chunks = [chunk.strip() for chunk in re.split(r"✅|\u2705", flat) if chunk.strip()]
    if len(chunks) < 2:
        return []

    name = re.sub(r"[:.]", "", normalize(chunks[0])).strip()
    if not name or len(name) < 3:
        return []

    sale_price = 0
    consumer_price = 0
    packaging = ""
    for chunk in chunks[1:]:
        clean_chunk = normalize(chunk)
        if re.search(r"(?:ندارد|نداره)", clean_chunk):
            continue
        if re.search(r"(?:باکس|کارتن|تعداد)", clean_chunk) and "قیمت" not in clean_chunk:
            packaging += clean_chunk + " "
        price_match = re.search(r"([\d,/\.\u06F0-\u06F9\u066B]{3,})", clean_chunk)
        if not price_match:
            continue
        price_val = parse_price(price_match.group(1))
        if price_val <= 100:
            continue
        if re.search(r"(?:مصرف|consumer)", clean_chunk, re.I):
            consumer_price = price_val
        elif re.search(r"(?:هر\s*عدد|دونه|unit|هر\s*کیلو|یک\s*عدد|قیمت\s*تکی)", clean_chunk, re.I):
            sale_price = price_val
        elif re.search(r"(?:باکس|box|کارتن)", clean_chunk, re.I) and "قیمت" in clean_chunk:
            continue
        elif not sale_price and not consumer_price and "قیمت" in clean_chunk:
            sale_price = price_val

    if sale_price > 0 or consumer_price > 0:
        return [{
            "name": name,
            "sale_price": sale_price,
            "consumer_price": consumer_price,
            "price": sale_price if sale_price > 0 else consumer_price,
            "actual_price": consumer_price,
            "packaging": packaging.strip(),
            "channel": "@top_shop_rahimi",
            "currency": "IRT",
            "stock_status": "Available",
            "description": content,
            "confidence": 0.9,
            "extraction_confidence": 0.9,
        }]
    return []


def extract_channel_nobelshop118(content: str) -> list[dict[str, Any]]:
    products: list[dict[str, Any]] = []
    lines = [line.strip() for line in (content or "").split("\n") if line.strip()]
    if len(lines) < 2:
        return products
    normalized_lines = [normalize(line) for line in lines]
    name = re.sub(r"[✅\u2705]", "", normalized_lines[0]).strip()
    if not name or len(name) < 3:
        return products

    packaging = ""
    for line in normalized_lines[:3]:
        if re.search(r"(?:عددی|باکس|کارتن|ورق|شیشه|بسته)", line):
            packaging = re.sub(r"[✅\u2705]", "", line).strip()

    sale_price = 0
    consumer_price = 0
    for line in normalized_lines:
        clean_line = re.sub(r"[✅\u2705]", "", line).strip()
        price_match = re.search(r"([\d,/\.\u06F0-\u06F9\u066B]{3,})", clean_line)
        if not price_match:
            continue
        price_val = parse_price(price_match.group(1))
        if price_val <= 100:
            continue
        if re.search(r"(?:مصرف|عمده|فروش ما|همکار|consumer|خرید)", clean_line, re.I):
            if re.search(r"(?:مصرف|consumer)", clean_line, re.I):
                consumer_price = price_val
            elif re.search(r"(?:عمده|فروش ما|همکار|خرید)", clean_line, re.I):
                sale_price = price_val
        elif not sale_price and not consumer_price:
            sale_price = price_val

    if sale_price > 0 or consumer_price > 0:
        products.append({
            "name": name,
            "sale_price": sale_price,
            "consumer_price": consumer_price,
            "price": sale_price if sale_price > 0 else consumer_price,
            "actual_price": consumer_price,
            "packaging": packaging,
            "description": content,
            "confidence": 0.85,
            "extraction_confidence": 0.85,
            "channel": "@nobelshop118",
            "currency": "IRT",
            "stock_status": "Available",
            "variation_type": "",
            "original_message": content,
        })
    return products


_UNIVERSAL_PRICE = re.compile(
    r"[:\s]([\d,]+(?:/[\d]{3})?|[\d,]+)(?:\s*(?:تومان|تومن|ت|T))?",
    re.I,
)
_UNIVERSAL_CONTACT = re.compile(r"(wa\.me|https?://|@|📞|تماس|واتساپ|خرید\s*آنلاین|خرید\s*انلاین|لینک)", re.I)
_UNIVERSAL_PRICE_LABEL = re.compile(r"(?:قیمت|Price|فی|Value|Amount|تومان|تومن|ت)", re.I)
_NEW_PRODUCT_SKIP = re.compile(r"^(?:تعداد|باکس|کارتن|لینک|آدرس|شعبه|تماس|واتساپ|wa\.me|https?://|@|📞)", re.I)
_OOS = re.compile(r"(?:تمام|ناموجود|❌)", re.I)


def extract_universal_products(content: str, channel_username: str) -> list[dict[str, Any]]:
    lines = [line.strip() for line in (content or "").split("\n") if line.strip()]
    products: list[dict[str, Any]] = []
    current: dict[str, Any] | None = None

    def finish(product: dict[str, Any] | None) -> None:
        if not product:
            return
        if product.get("price", 0) > 0 or product.get("stock_status") == "Out of Stock":
            products.append(finalize_product(product, channel_username))

    for line in lines:
        contact_line = bool(_UNIVERSAL_CONTACT.search(line))
        has_price = bool(_UNIVERSAL_PRICE.search(line)) and bool(re.search(r"\d", line))
        has_price_label = bool(_UNIVERSAL_PRICE_LABEL.search(line))
        is_simple_price = len(line.strip()) < 20 and has_price
        is_price_line = (not contact_line) and ((has_price and has_price_label) or is_simple_price)
        is_new = (
            not is_price_line
            and len(line) > 3
            and not _NEW_PRODUCT_SKIP.search(line)
            and (current is None or current.get("price", 0) > 0)
        )
        if is_new:
            finish(current)
            current = {
                "raw_name": line,
                "name": clean_product_name(line),
                "sale_price": 0,
                "actual_price": 0,
                "price": 0,
                "consumer_price": 0,
                "packaging": extract_packaging(line),
                "volume": extract_volume(line),
                "stock_status": "Out of Stock" if _OOS.search(line) else "Available",
                "description": line,
                "extraction_confidence": 0.8,
            }
            continue
        if current is None:
            continue
        current["description"] += "\n" + line
        if _OOS.search(line):
            current["stock_status"] = "Out of Stock"
        if not current.get("packaging"):
            packaging = extract_packaging(line)
            if packaging:
                current["packaging"] = packaging
        if not current.get("volume"):
            volume = extract_volume(line)
            if volume:
                current["volume"] = volume
        if is_price_line:
            prices = extract_prices_from_line(line)
            if prices["sale"] > 0:
                current["sale_price"] = prices["sale"]
                current["price"] = prices["sale"]
            if prices["consumer"] > 0:
                current["actual_price"] = prices["consumer"]
                current["consumer_price"] = prices["consumer"]
            current["extraction_confidence"] = max(current["extraction_confidence"], 0.9)
            if current["price"] > 0 and current["consumer_price"] > 0 and current["price"] > current["consumer_price"]:
                current["price"], current["consumer_price"] = current["consumer_price"], current["price"]
                current["sale_price"], current["actual_price"] = current["actual_price"], current["sale_price"]

    finish(current)
    return products


def extract_products(content: str, channel_username: str) -> list[dict[str, Any]]:
    if channel_username == "@bonakdarjavan":
        return extract_channel_bonakdarjavan(content)
    if channel_username == "@top_shop_rahimi":
        return extract_channel_top_shop_rahimi(content)
    if channel_username == "@nobelshop118":
        return extract_channel_nobelshop118(content)
    return extract_universal_products(content, channel_username)


def quality_check(product: dict[str, Any], existing_rows: list[list[Any]], headers: list[str]) -> dict[str, Any]:
    qa = {"requires_review": False, "reason": ""}
    sale = product.get("sale_price") or product.get("price") or 0
    actual = product.get("actual_price") or product.get("consumer_price") or 0
    confidence = product.get("extraction_confidence") or product.get("confidence") or 0
    if actual and sale:
        discount = 1 - (sale / actual)
        if discount > 0.8 or discount < 0.05:
            qa["requires_review"] = True
            qa["reason"] = f"Unusual discount {round(discount * 100)}%"
    if confidence < 0.85:
        qa["requires_review"] = True
        extra = "low confidence"
        qa["reason"] = f"{qa['reason']}; {extra}" if qa["reason"] else extra

    try:
        name_idx = headers.index("Product Name")
        sale_idx = headers.index("Sale Price")
    except ValueError:
        return qa

    total = 0
    count = 0
    target = (product.get("name") or "").lower()
    for row in existing_rows[1:]:
        existing_name = row[name_idx] if name_idx < len(row) else ""
        if str(existing_name or "").lower() != target:
            continue
        raw_sale = row[sale_idx] if sale_idx < len(row) else ""
        try:
            value = int(float(raw_sale))
        except (TypeError, ValueError):
            continue
        if value > 0:
            total += value
            count += 1
    if count >= 3 and sale:
        average = total / count
        if abs(sale - average) / average > 0.5:
            qa["requires_review"] = True
            extra = "deviates from history"
            qa["reason"] = f"{qa['reason']}; {extra}" if qa["reason"] else extra
    return qa
