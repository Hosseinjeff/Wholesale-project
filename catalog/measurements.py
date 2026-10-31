"""Conservative parsing and normalization of explicit Persian product measures."""

from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from fractions import Fraction
import re


_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
_NUMBER = (
    r"(?P<number>(?:\d{1,3}(?:,\d{3})+|\d{1,6})"
    r"(?:\s*(?:/|⁄)\s*\d{1,4}|\s*[.٫]\s*\d{1,4})?)"
)
_VOLUME_UNIT = (
    r"(?P<unit>milliliters?|millilitres?|liters?|litres?|"
    r"میلی\s*[\u200c\s]*لیتر|میلیلیتر|میلی\s*لیتری|"
    r"سی\s*[\u200c\s]*سی|لیتر(?:ی)?|لیتری|میل|مل|ml|cc|l|ل)"
)
_WEIGHT_UNIT = (
    r"(?P<unit>milligrams?|kilograms?|grams?|"
    r"میلی\s*[\u200c\s]*گرم|میلیگرم|کیلو\s*[\u200c\s]*گرمی|"
    r"کیلو\s*[\u200c\s]*گرم|کیلوگرم|کیلویی|کیلو|گرمی|گرم|mg|kg|g|گ)"
)
_UNIT_PATTERNS = {
    "volume": re.compile(r"(?<!\d)" + _NUMBER + r"\s*" + _VOLUME_UNIT + r"(?![\w])", re.I),
    "weight": re.compile(r"(?<!\d)" + _NUMBER + r"\s*" + _WEIGHT_UNIT + r"(?![\w])", re.I),
}


@dataclass(frozen=True)
class Measurement:
    value: Fraction
    unit: str
    base_value: Fraction

    @property
    def display(self) -> str:
        return f"{_format_fraction(self.value)} {self.unit}"


def _format_fraction(value: Fraction) -> str:
    denominator = value.denominator
    reduced = denominator
    while reduced % 2 == 0:
        reduced //= 2
    while reduced % 5 == 0:
        reduced //= 5
    if reduced != 1:
        return f"{value.numerator}/{value.denominator}"
    decimal = Decimal(value.numerator) / Decimal(value.denominator)
    return format(decimal.normalize(), "f")


def _parse_value(raw: str) -> Fraction | None:
    raw = raw.translate(_DIGITS).replace(" ", "").replace(",", "")
    try:
        if "⁄" in raw:
            numerator, denominator = raw.split("⁄", maxsplit=1)
            denominator_value = int(denominator)
            if denominator_value == 0:
                return None
            value = Fraction(int(numerator), denominator_value)
        else:
            # In Persian supplier titles, ASCII slash is commonly a decimal
            # separator: 1/5 means 1.5. Keep the dedicated fraction slash for
            # explicit mathematical fractions.
            value = Fraction(Decimal(raw.replace("/", ".").replace("٫", ".")))
    except (InvalidOperation, ValueError, ZeroDivisionError):
        return None
    if value <= 0 or value > 1_000_000:
        return None
    return value


def _canonical_unit(raw: str, kind: str) -> tuple[str, Fraction] | None:
    key = re.sub(r"[\s\u200c]+", "", raw.translate(_DIGITS).lower())
    if kind == "volume":
        if key in {"ل", "l", "لیتر", "لیتری", "liter", "liters", "litre", "litres"}:
            return "لیتر", Fraction(1000)
        if key in {"میلیلیتر", "میل", "مل", "ml", "cc", "سیسی", "milliliter", "milliliters", "millilitre", "millilitres"}:
            return "میل", Fraction(1)
        return None
    if kind == "weight":
        if key in {"کیلو", "کیلوگرم", "کیلوگرمی", "کیلویی", "kg", "kilogram", "kilograms"}:
            return "کیلوگرم", Fraction(1000)
        if key in {"میلیگرم", "mg", "milligram", "milligrams"}:
            return "میلی\u200cگرم", Fraction(1, 1000)
        if key in {"گرم", "گرمی", "گ", "g", "gram", "grams"}:
            return "گرم", Fraction(1)
    return None


def parse_measurements(text: str, kind: str) -> list[Measurement]:
    """Parse only number+unit pairs explicitly present in a title or field.

    ASCII slash in Persian titles is a decimal separator (``1/2ل`` means 1.2
    liters). The dedicated fraction slash ``⁄`` represents an exact fraction.
    Price and package suffixes such as ``(138ف)`` and ``20ع`` are ignored.
    """
    if kind not in _UNIT_PATTERNS:
        raise ValueError("kind must be 'volume' or 'weight'")
    source = str(text or "").translate(_DIGITS)
    found: list[Measurement] = []
    for match in _UNIT_PATTERNS[kind].finditer(source):
        value = _parse_value(match.group("number"))
        unit = _canonical_unit(match.group("unit"), kind)
        if value is None or unit is None:
            continue
        label, multiplier = unit
        candidate = Measurement(value, label, value * multiplier)
        if candidate not in found:
            found.append(candidate)
    return found


def extract_measurement(text: str, kind: str) -> str:
    """Return the explicit measurements in a compact, stable display form."""
    return "، ".join(item.display for item in parse_measurements(text, kind))


def measurement_signature(text: str, kind: str) -> tuple[Fraction, ...]:
    """Return base-unit values for comparing extracted fields with title evidence."""
    return tuple(item.base_value for item in parse_measurements(text, kind))


def strip_measurement_tokens(text: str) -> str:
    """Remove recognized number+unit pairs while preserving all other title text."""
    source = str(text or "")
    spans = []
    for kind, pattern in _UNIT_PATTERNS.items():
        for match in pattern.finditer(source):
            if (_parse_value(match.group("number")) is not None
                    and _canonical_unit(match.group("unit"), kind)):
                spans.append((match.start(), match.end()))
    for start, end in sorted(spans, reverse=True):
        source = source[:start] + " " + source[end:]
    return source


def normalize_measurement_tokens(text: str) -> str:
    """Canonicalize recognized size tokens while preserving surrounding title text."""
    source = str(text or "")
    matches = []
    for kind, pattern in _UNIT_PATTERNS.items():
        for match in pattern.finditer(source):
            value = _parse_value(match.group("number"))
            unit = _canonical_unit(match.group("unit"), kind)
            if value is not None and unit is not None:
                matches.append((match.start(), match.end(), f"{_format_fraction(value)} {unit[0]}"))
    for start, end, replacement in sorted(matches, reverse=True):
        source = source[:start] + replacement + source[end:]
    return source
