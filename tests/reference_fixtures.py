"""Small WooCommerce source fixtures shared by reference workflow tests."""
from datetime import datetime, timezone

from reference_import.importer import _build_woocommerce_candidate


def woocommerce_product(**overrides):
    product = {
        'id': 86163,
        'sku': 'TEA-12',
        'name': 'چای نمونه ۱۲ عددی (۴۵ف) ۲۵۰گرم ۵۰۰میل',
        'categories': [{'id': 12, 'name': 'نوشیدنی'}],
        'attributes': [],
        'prices': {
            'price': '480000',
            'regular_price': '500000',
            'sale_price': '480000',
            'currency_code': 'IRT',
            'currency_minor_unit': 0,
        },
    }
    product.update(overrides)
    return product


def staged_candidate(timestamp=None, products=None, *, page_count=1, currency='IRT'):
    timestamp = timestamp or datetime.now(timezone.utc).isoformat()
    return _build_woocommerce_candidate(products or [woocommerce_product()], page_count,
                                        currency, timestamp)
