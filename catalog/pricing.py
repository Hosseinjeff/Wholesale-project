"""Discount band and cart quote.

The supplier sale price is the cost. The consumer price is the regular price.
The gap between them is the spread. A thin spread keeps most of the gap as
profit. A wide spread leaves more of it for the customer. The ceiling is the
most we charge, already under the regular price. The floor is the least we
charge, still clear of the cost by the margin rule.

Nine cells on the Shop sheet are the curve. The site reads those cells and
applies the same steps as the formulas under them, so editing a cell moves
every product and every cart.
"""

from __future__ import annotations

from typing import Any

from catalog.extract import PRODUCT_HEADERS


# A common tuna can is offered near 200,000 toman. A shell is 24 cans,
# about 4,800,000. The cart reaches the ceiling discount at four shells.
CANS_PER_SHELL = 24
SHELLS_TO_CEILING = 4
COMMON_TUNA_CAN = 200_000
CEILING_UNLOCK = COMMON_TUNA_CAN * CANS_PER_SHELL * SHELLS_TO_CEILING

SETTING_FIELDS = (
    ("Min Margin", "min_margin", 0.12, True),
    ("Min Profit", "min_profit", 5000, False),
    ("Spread Low", "spread_low", 0.10, True),
    ("Spread High", "spread_high", 0.60, True),
    ("Profit Share Low", "profit_share_low", 0.70, True),
    ("Profit Share High", "profit_share_high", 0.35, True),
    ("Opening Share Low", "opening_share_low", 0.20, True),
    ("Opening Share High", "opening_share_high", 0.25, True),
    ("Unlock Target", "unlock_target", CEILING_UNLOCK, False),
)

SHOP_HEADERS = [
    "Product ID",
    "Product Name",
    "Channel",
    "Cost",
    "Regular",
    "Spread",
    "Floor",
    "Ceiling",
    "Opening Rate",
    "Depth Rate",
    "Max Discount",
    "On Shop",
]

SHOP_HEADER_ROW = 11
SHOP_FORMULA_ROW = 12
SHOP_PRODUCT_LIMIT = 2000
_PUBLIC_FIELDS = (
    "product_id",
    "name",
    "channel",
    "category",
    "regular",
    "floor",
    "ceiling",
    "opening_rate",
    "depth_rate",
    "max_discount",
    "spread",
    "stock_status",
)
_HIDDEN_STATUSES = {"skipped", "needs_review"}


class PricingSettings:
    def __init__(
        self,
        min_margin: float = 0.12,
        min_profit: int = 5000,
        spread_low: float = 0.10,
        spread_high: float = 0.60,
        profit_share_low: float = 0.70,
        profit_share_high: float = 0.35,
        opening_share_low: float = 0.20,
        opening_share_high: float = 0.25,
        unlock_target: int = CEILING_UNLOCK,
    ) -> None:
        self.min_margin = float(min_margin)
        self.min_profit = int(min_profit)
        self.spread_low = float(spread_low)
        self.spread_high = float(spread_high)
        self.profit_share_low = float(profit_share_low)
        self.profit_share_high = float(profit_share_high)
        self.opening_share_low = float(opening_share_low)
        self.opening_share_high = float(opening_share_high)
        self.unlock_target = int(unlock_target)

    def as_dict(self) -> dict[str, float | int]:
        return {
            "min_margin": self.min_margin,
            "min_profit": self.min_profit,
            "spread_low": self.spread_low,
            "spread_high": self.spread_high,
            "profit_share_low": self.profit_share_low,
            "profit_share_high": self.profit_share_high,
            "opening_share_low": self.opening_share_low,
            "opening_share_high": self.opening_share_high,
            "unlock_target": self.unlock_target,
        }


def ceiling_unlock(can_price: int = COMMON_TUNA_CAN) -> int:
    """Shelf total at which the cart is on the ceiling discount.

    ``can_price`` is one tuna can. A shell is 24 cans. Four shells open the ceiling.
    """
    return int(can_price) * CANS_PER_SHELL * SHELLS_TO_CEILING


def default_settings() -> PricingSettings:
    return PricingSettings()


def _clamp(value: float, low: float, high: float) -> float:
    return max(low, min(high, value))


def _round_up(value: float) -> int:
    """Match Google Sheets ROUNDUP(value, 0) for a positive toman amount."""
    nearest = round(float(value), 8)
    whole = int(nearest)
    if nearest - whole > 1e-8:
        return whole + 1
    return whole


def _round_down(value: float) -> int:
    """Match Google Sheets ROUNDDOWN(value, 0) for a positive toman amount."""
    nearest = round(float(value), 8)
    return int(nearest)


def _round_half_up(value: float) -> int:
    nearest = round(float(value), 8)
    whole = int(nearest)
    if nearest - whole >= 0.5 - 1e-8:
        return whole + 1
    return whole


def _blank_band(cost: int, regular: int) -> dict[str, Any]:
    regular_price = regular if regular > 0 else 0
    return {
        "cost": cost if cost > 0 else 0,
        "regular": regular_price,
        "spread": 0.0,
        "floor": regular_price,
        "ceiling": regular_price,
        "opening_rate": 0.0,
        "depth_rate": 0.0,
        "max_discount": 0.0,
        "discountable": False,
    }


def price_band(cost: int, regular: int, settings: PricingSettings | None = None) -> dict[str, Any]:
    """Set the ceiling and the floor from the supplier spread."""
    settings = settings or default_settings()
    cost = int(cost or 0)
    regular = int(regular or 0)
    if cost <= 0 or regular <= 0 or regular <= cost:
        return _blank_band(cost, regular)

    gap = regular - cost
    spread = gap / regular
    span = settings.spread_high - settings.spread_low
    if span <= 0:
        t = 0.0
    else:
        t = _clamp((spread - settings.spread_low) / span, 0.0, 1.0)
    profit_share = settings.profit_share_low + (settings.profit_share_high - settings.profit_share_low) * t
    opening_share = settings.opening_share_low + (settings.opening_share_high - settings.opening_share_low) * t
    curve_profit = profit_share * gap
    required = max(cost * settings.min_margin, float(settings.min_profit))
    profit = max(curve_profit, required)
    raw_floor = cost + profit
    if raw_floor >= regular:
        return _blank_band(cost, regular)

    floor_price = _round_up(raw_floor)
    if floor_price >= regular:
        return _blank_band(cost, regular)
    room = regular - floor_price
    opening_toman = min(opening_share * gap, room)
    ceiling = _round_down(regular - opening_toman)
    if ceiling < floor_price:
        ceiling = floor_price
    if ceiling > regular:
        ceiling = regular
    opening_rate = (regular - ceiling) / regular
    depth_rate = (ceiling - floor_price) / regular
    max_discount = (regular - floor_price) / regular
    return {
        "cost": cost,
        "regular": regular,
        "spread": spread,
        "floor": floor_price,
        "ceiling": ceiling,
        "opening_rate": opening_rate,
        "depth_rate": depth_rate,
        "max_discount": max_discount,
        "discountable": max_discount > 0,
    }


def quote_lines(lines: list[dict[str, Any]], unlock_target: int) -> dict[str, Any]:
    """Price the current cart. Adding or removing a line reprices every line."""
    prepared = [line for line in lines if int(line.get("qty") or 0) > 0 and int(line.get("regular") or 0) > 0]
    regular_total = sum(int(line["regular"]) * int(line["qty"]) for line in prepared)
    if regular_total <= 0 or int(unlock_target or 0) <= 0:
        progress = 0.0
    else:
        progress = min(1.0, regular_total / int(unlock_target))

    quoted = []
    discount_total = 0
    pay_total = 0
    for line in prepared:
        regular = int(line["regular"])
        ceiling = int(line["ceiling"])
        floor_price = int(line["floor"])
        qty = int(line["qty"])
        raw_unit = ceiling + (floor_price - ceiling) * progress
        unit = _round_half_up(raw_unit)
        low = min(floor_price, ceiling)
        high = max(floor_price, ceiling, low)
        high = min(high, regular)
        if unit < low:
            unit = low
        if unit > high:
            unit = high
        discount = regular - unit
        amount = discount * qty
        discount_total += amount
        pay_total += unit * qty
        quoted.append({
            "product_id": line.get("product_id") or "",
            "name": line.get("name") or "",
            "channel": line.get("channel") or "",
            "qty": qty,
            "regular": regular,
            "floor": floor_price,
            "ceiling": ceiling,
            "unit_price": unit,
            "discount_rate": discount / regular,
            "discount_amount": amount,
        })
    return {
        "progress": progress if prepared else 0.0,
        "regular_total": regular_total,
        "discount_total": discount_total,
        "pay_total": pay_total,
        "discount_rate": (discount_total / regular_total) if regular_total else 0.0,
        "lines": quoted,
    }


def _positive(value: Any) -> int | None:
    if value is None or value == "":
        return None
    try:
        number = int(float(value))
    except (TypeError, ValueError):
        return None
    return number if number > 0 else None


def _as_float(value: Any, default: float, percent: bool) -> float:
    if isinstance(value, bool) or value is None or value == "":
        return default
    if isinstance(value, (int, float)):
        number = float(value)
    else:
        text = str(value).strip().replace("%", "").replace(",", "")
        if not text:
            return default
        try:
            number = float(text)
        except ValueError:
            return default
    if percent and number >= 2:
        number = number / 100
    return number


def settings_from_rows(rows: list[list[Any]] | None) -> PricingSettings:
    """Read the Shop sheet. A blank cell keeps the default. A typed cell wins."""
    found: dict[str, Any] = {}
    for row in rows or []:
        if not row:
            continue
        label = str(row[0] or "").strip()
        if label in found or len(row) < 2 or row[1] in ("", None):
            continue
        found[label] = row[1]
    values: dict[str, Any] = {}
    for label, field, default, percent in SETTING_FIELDS:
        if field == "min_profit" or field == "unlock_target":
            number = _as_float(found.get(label), float(default), False)
            values[field] = int(round(number))
        else:
            values[field] = _as_float(found.get(label), float(default), percent)
    return PricingSettings(**values)


def _public_product(source: dict[str, Any], band: dict[str, Any]) -> dict[str, Any]:
    product = {
        "product_id": str(source.get("product_id") or ""),
        "name": source.get("name") or "",
        "channel": source.get("channel") or "",
        "category": source.get("category") or "",
        "stock_status": source.get("stock_status") or "Available",
    }
    for field in ("regular", "floor", "ceiling", "opening_rate", "depth_rate", "max_discount", "spread"):
        product[field] = band[field]
    return {field: product[field] for field in _PUBLIC_FIELDS}


def product_from_record(record: dict[str, Any], settings: PricingSettings | None = None) -> dict[str, Any] | None:
    status = str(record.get("Status") or "").strip().lower()
    if status in _HIDDEN_STATUSES:
        return None
    name = str(record.get("Product Name") or "").strip()
    if not name:
        return None
    cost = _positive(record.get("Sale Price"))
    regular = _positive(record.get("Consumer Price")) or _positive(record.get("Actual Price"))
    if cost is None or regular is None:
        return None
    band = price_band(cost, regular, settings)
    if not band["discountable"]:
        return None
    return _public_product({
        "product_id": record.get("Product ID") or "",
        "name": name,
        "channel": record.get("Channel Username") or "",
        "category": record.get("Category") or "",
        "stock_status": record.get("Stock Status") or "Available",
    }, band)


def catalog_from_records(records: list[dict[str, Any]], settings: PricingSettings | None = None) -> list[dict[str, Any]]:
    settings = settings or default_settings()
    products = []
    for record in records:
        product = product_from_record(record, settings)
        if product and product["product_id"]:
            products.append(product)
    return products


def preview_records() -> list[dict[str, Any]]:
    """Three spreads used to show the shop before a sheet is connected."""
    return [
        {
            "Product ID": "preview-bear",
            "Product Name": "بیگ بیر بلوبری",
            "Channel Username": "@top_shop_rahimi",
            "Sale Price": 30833,
            "Consumer Price": 60000,
            "Stock Status": "Available",
            "Status": "imported",
        },
        {
            "Product ID": "preview-tuna",
            "Product Name": "تن ماهی شیان",
            "Channel Username": "@bonakdarjavan",
            "Sale Price": 40000,
            "Consumer Price": 50000,
            "Stock Status": "Available",
            "Status": "imported",
        },
        {
            "Product ID": "preview-tea",
            "Product Name": "چای کیسه ای شهرزاد",
            "Channel Username": "@bonakdarjavan",
            "Sale Price": 30000,
            "Consumer Price": 100000,
            "Stock Status": "Available",
            "Status": "imported",
        },
    ]


def resolve_quote(products: list[dict[str, Any]], requests: list[dict[str, Any]], unlock_target: int) -> dict[str, Any]:
    """Quote a cart from product ids. Unknown ids are reported and left out."""
    by_id = {str(product.get("product_id") or ""): product for product in products}
    quantities: dict[str, int] = {}
    order: list[str] = []
    missing: list[str] = []
    for request in requests or []:
        product_id = str(request.get("product_id") or "")
        try:
            qty = int(request.get("qty") or 0)
        except (TypeError, ValueError):
            missing.append(product_id)
            continue
        if qty <= 0 or not product_id:
            continue
        if product_id not in by_id:
            missing.append(product_id)
            continue
        if product_id not in quantities:
            order.append(product_id)
            quantities[product_id] = 0
        quantities[product_id] += qty
    lines = []
    for product_id in order:
        product = by_id[product_id]
        lines.append({**product, "qty": quantities[product_id]})
    quote = quote_lines(lines, unlock_target)
    quote["missing"] = missing
    return quote


def column_letter(index: int) -> str:
    letters = ""
    while index:
        index, remainder = divmod(index - 1, 26)
        letters = chr(65 + remainder) + letters
    return letters


def _product_column(header: str) -> str:
    return column_letter(PRODUCT_HEADERS.index(header) + 1)


def _args(parts: list[str], sep: str) -> str:
    return sep.join(parts)


def _formula(expression: str, sep: str) -> str:
    if sep != ",":
        expression = expression.replace(",", sep)
    return "=" + expression


def _let_result(result: str) -> str:
    return (
        "LET("
        "spread, IF(regular>cost, (regular-cost)/regular, 0), "
        "span, $B$4-$B$3, "
        "rawt, IF(span=0, 0, (spread-$B$3)/span), "
        "t, IF(rawt<0, 0, IF(rawt>1, 1, rawt)), "
        "pshare, $B$5+($B$6-$B$5)*t, "
        "oshare, $B$7+($B$8-$B$7)*t, "
        "gap, IF(regular>cost, regular-cost, 0), "
        "curve, pshare*gap, "
        "required, IF(cost*$B$1>$B$2, cost*$B$1, $B$2), "
        "profit, IF(curve>required, curve, required), "
        "rawf, cost+profit, "
        "floorr, IF(OR(cost<=0, regular<=0, regular<=cost, rawf>=regular), IF(regular>0, regular, 0), ROUNDUP(rawf, 0)), "
        "room, IF(regular>floorr, regular-floorr, 0), "
        "opening, IF(oshare*gap<room, oshare*gap, room), "
        "rawc, regular-opening, "
        "ceiling, IF(OR(cost<=0, regular<=0, regular<=cost, floorr>=regular), IF(regular>0, regular, 0), IF(ROUNDDOWN(rawc, 0)<floorr, floorr, ROUNDDOWN(rawc, 0))), "
        f"{result})"
    )


def _map_formula(result: str, sep: str, band: str) -> str:
    body = _let_result(result)
    expression = (
        f"MAP({band}, LAMBDA(cost, regular, "
        f'IF(cost="", "", {body})))'
    )
    return _formula(expression, sep)


def shop_formulas(separator: str = ",") -> list[str]:
    """Formulas for Shop!A12:L12. They follow price_band and read $B$1:$B$8."""
    sep = ";" if separator == ";" else ","
    last = 1 + SHOP_PRODUCT_LIMIT
    shop_last = SHOP_FORMULA_ROW + SHOP_PRODUCT_LIMIT - 1
    name = _product_column("Product Name")
    product_id = _product_column("Product ID")
    channel = _product_column("Channel Username")
    sale = _product_column("Sale Price")
    consumer = _product_column("Consumer Price")
    actual = _product_column("Actual Price")
    present = f'Products!{name}2:{name}{last}=""'

    def pull(expression: str) -> str:
        return _formula(f'ARRAYFORMULA(IF({present}, "", {expression}))', sep)

    cost = pull(f"IFERROR(VALUE(Products!{sale}2:{sale}{last}), 0)")
    regular = pull(
        "IF("
        f"IFERROR(VALUE(Products!{consumer}2:{consumer}{last}), 0)>0, "
        f"VALUE(Products!{consumer}2:{consumer}{last}), "
        f"IFERROR(VALUE(Products!{actual}2:{actual}{last}), 0))"
    )
    band = f"D{SHOP_FORMULA_ROW}:D{shop_last}, E{SHOP_FORMULA_ROW}:E{shop_last}"
    return [
        pull(f"Products!{product_id}2:{product_id}{last}"),
        pull(f"Products!{name}2:{name}{last}"),
        pull(f"Products!{channel}2:{channel}{last}"),
        cost,
        regular,
        _map_formula('IF(OR(cost<=0, regular<=0, regular<=cost), 0, spread)', sep, band),
        _map_formula("floorr", sep, band),
        _map_formula("ceiling", sep, band),
        _map_formula('IF(OR(regular<=0, floorr>=regular), 0, (regular-ceiling)/regular)', sep, band),
        _map_formula('IF(OR(regular<=0, ceiling<=floorr), 0, (ceiling-floorr)/regular)', sep, band),
        _map_formula('IF(OR(regular<=0, floorr>=regular), 0, (regular-floorr)/regular)', sep, band),
        _map_formula('IF(OR(regular<=0, floorr>=regular), FALSE, TRUE)', sep, band),
    ]


def shop_setting_rows(existing: list[list[Any]] | None) -> list[list[Any]]:
    """Column B keeps a value the operator already typed."""
    current = settings_from_rows(existing)
    values = current.as_dict()
    rows = []
    for label, field, _default, _percent in SETTING_FIELDS:
        rows.append([label, values[field]])
    return rows


def formula_separator(locale: str) -> str:
    if not locale or locale.lower().startswith("en"):
        return ","
    return ";"
