"""Confirmed first-release thresholds shared by supplier-price and delivery adapters."""
from datetime import timedelta

WARNING_AGE = timedelta(days=7)
BLOCKING_AGE = timedelta(days=14)
TEHRAN_DELIVERY_FEE_TOMAN = 250_000
FREE_DELIVERY_GOODS_SUBTOTAL_TOMAN = 5_000_000


def supplier_price_state(last_verified, now, *, warning=WARNING_AGE, blocking=BLOCKING_AGE):
    """Evaluate verified supplier-offer age; reference snapshot age is separate."""
    if now.tzinfo is None or now.utcoffset() is None:
        raise ValueError('now must include a timezone')
    if warning <= timedelta(0) or blocking <= warning:
        raise ValueError('invalid thresholds')
    if last_verified is None:
        return 'needs_review'
    if last_verified.tzinfo is None or last_verified.utcoffset() is None or last_verified > now:
        return 'needs_review'
    age = now - last_verified
    if age >= blocking: return 'blocked'
    if age >= warning: return 'warning'
    return 'current'


def tehran_delivery(goods_subtotal_toman, *, fee=TEHRAN_DELIVERY_FEE_TOMAN,
                    threshold=FREE_DELIVERY_GOODS_SUBTOTAL_TOMAN):
    """Quote Tehran delivery from the goods subtotal after product discounts.

    The caller must establish the delivery address is in the approved territory;
    an empty cart never qualifies. Integer toman avoids rial and rounding ambiguity.
    """
    for value in (goods_subtotal_toman, fee, threshold):
        if isinstance(value, bool) or not isinstance(value, int) or value < 0:
            raise ValueError('non-negative whole toman required')
    if threshold <= 0: raise ValueError('positive threshold required')
    if goods_subtotal_toman == 0:
        return {'delivery_fee': 0, 'delivery_discount': 0, 'delivery_payable': 0}
    saving = fee if goods_subtotal_toman >= threshold else 0
    return {'delivery_fee': fee, 'delivery_discount': saving, 'delivery_payable': fee-saving}
