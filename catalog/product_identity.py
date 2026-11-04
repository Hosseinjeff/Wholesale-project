"""Stable product identity across source titles and extracted dimensions."""
from __future__ import annotations

import hashlib
import json
import re
from typing import Any

from catalog.extract import normalize, pack_count, peel_title_pack
from catalog.measurements import measurement_signature


def _text(value: Any) -> str:
    return re.sub(r"\s+", " ", normalize(str(value or ""))).strip()


def _name_key(value: Any) -> str:
    peeled, _pack = peel_title_pack(_text(value))
    return re.sub(r"[\s،,:：\-]+$", "", peeled).strip().lower()


def _legacy_name_key(value: Any) -> str:
    # This must remain byte-for-byte compatible with the old sync._product_key
    # behavior so already-published products retain their source IDs and SKUs.
    peeled, _pack = peel_title_pack(str(value or ""))
    return re.sub(r"[\s،,:：\-]+$", "", peeled).strip().lower()


def _measure_key(value: Any, name: Any, kind: str) -> str:
    supplied = _text(value)
    title = _text(name)
    signature = measurement_signature(supplied, kind) if supplied else ()
    if not signature:
        signature = measurement_signature(title, kind)
    if signature:
        return ",".join(str(item) for item in signature)
    return _name_key(supplied) if supplied else ""


def _positive_int(value: Any) -> int:
    match = re.search(r"\d+", _text(value))
    return int(match.group(0)) if match else 0


def product_identity_parts(record: dict[str, Any]) -> tuple[str, ...]:
    """Identity includes supplier, base title, explicit variant, and sellable size."""
    name = record.get("Product Name") or record.get("name") or ""
    channel = (record.get("Channel Username") or record.get("channel_username")
               or record.get("channel") or "")
    name_key = _name_key(name)
    variant = _name_key(record.get("Variation Type") or record.get("variation_type")
                        or record.get("variant") or "")
    # A variant already written into the full product title must not split the same
    # product differently when older Offers rows lack the side-field value.
    if variant and re.search(r"(?<!\w)" + re.escape(variant) + r"(?!\w)", name_key):
        variant = ""
    volume = _measure_key(record.get("Volume") or record.get("volume"), name, "volume")
    weight = _measure_key(record.get("Weight") or record.get("weight"), name, "weight")
    packaging = record.get("Packaging") or record.get("packaging") or ""
    package_count = (_positive_int(record.get("Package Count") or record.get("package_count"))
                     or pack_count(str(packaging or ""), str(name or "")) or 0)
    return (
        str(channel or "").strip().casefold(),
        name_key,
        variant,
        volume,
        weight,
        str(package_count),
    )


def product_identity_key(record: dict[str, Any]) -> str:
    return json.dumps(product_identity_parts(record), ensure_ascii=False, separators=(",", ":"))


def legacy_product_id(name: str, channel: str = "") -> str:
    """The pre-dimension ID; retained for existing Products rows and Woo SKUs."""
    identity = f"{str(channel or '').strip().casefold()}|{_legacy_name_key(name)}"
    return "p_" + hashlib.sha256(identity.encode("utf-8")).hexdigest()[:24]


def product_row_id(record: dict[str, Any]) -> str:
    """Generate a dimension-aware ID for new rows, without re-keying existing ones."""
    name = str(record.get("Product Name") or record.get("name") or "")
    channel = str(record.get("Channel Username") or record.get("channel_username")
                  or record.get("channel") or "")
    parts = product_identity_parts(record)
    if not any(parts[2:]):
        return legacy_product_id(name, channel)
    return "p_" + hashlib.sha256(
        json.dumps(parts, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    ).hexdigest()[:24]


def woo_sku(record: dict[str, Any]) -> str:
    """Keep existing legacy SKUs stable; disambiguate rows with dimension IDs."""
    name = str(record.get("Product Name") or record.get("name") or "")
    channel = str(record.get("Channel Username") or record.get("channel_username")
                  or record.get("channel") or "")
    product_id = str(record.get("Product ID") or record.get("product_id") or "").strip()
    legacy_id = legacy_product_id(name, channel)
    dimension_id = product_row_id(record)
    # Only IDs created by the new dimension-aware scheme get a new SKU. Older
    # sheets can contain other stable IDs, and their Woo SKU was still derived
    # from supplier + title; changing it would make reconciliation expect a SKU
    # that the existing Woo product never received.
    if not product_id or product_id == legacy_id or product_id != dimension_id:
        return hashlib.sha256(f"{channel}|{name}".encode()).hexdigest()[:12]
    return hashlib.sha256(f"product-row|{product_id}".encode()).hexdigest()[:12]


def identity_fields(record: dict[str, Any]) -> dict[str, Any]:
    """Copy only the extracted identity dimensions for sheet row matching."""
    return {
        "Variation Type": record.get("Variation Type") or record.get("variation_type") or record.get("variant") or "",
        "Volume": record.get("Volume") or record.get("volume") or "",
        "Weight": record.get("Weight") or record.get("weight") or "",
        "Package Count": record.get("Package Count") or record.get("package_count") or "",
        "Packaging": record.get("Packaging") or record.get("packaging") or "",
    }


def identity_key_from_parts(name: str, channel: str, variation_type: str = "",
                             volume: str = "", weight: str = "", package_count: Any = "",
                             packaging: str = "") -> str:
    return product_identity_key({
        "Product Name": name,
        "Channel Username": channel,
        "Variation Type": variation_type,
        "Volume": volume,
        "Weight": weight,
        "Package Count": package_count,
        "Packaging": packaging,
    })
