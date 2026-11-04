"""Cross-check the editable product book against its append-only offer evidence."""
from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any

from catalog.product_identity import product_identity_key

_SOURCE_FIELDS = ('Channel Username', 'Channel', 'Batch ID', 'Message Timestamp',
                  'Source Timestamp Basis', 'Source Post URL')


def product_offer_link_issues(products: list[dict[str, Any]],
                              offers: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Return current product rows whose latest source offer cannot be verified."""
    offer_by_id: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for offer in offers:
        offer_id = str(offer.get('Offer ID') or '').strip()
        if offer_id:
            offer_by_id[offer_id].append(offer)

    product_id_counts = Counter(str(row.get('Product ID') or '').strip()
                                for row in products
                                if str(row.get('Product ID') or '').strip())
    product_identity_counts = Counter(product_identity_key(row) for row in products
        if str(row.get('Product Name') or '').strip())
    issues = []
    for index, product in enumerate(products):
        product_id = str(product.get('Product ID') or '').strip()
        latest_offer_id = str(product.get('Latest Offer ID') or '').strip()
        codes = []
        mismatched_fields = []
        if not product_id:
            codes.append('missing_product_id')
        elif product_id_counts[product_id] > 1:
            codes.append('duplicate_product_id')
        if (str(product.get('Product Name') or '').strip()
                and product_identity_counts[product_identity_key(product)] > 1):
            codes.append('duplicate_product_identity')
        if not latest_offer_id:
            codes.append('missing_latest_offer')
        else:
            linked = offer_by_id.get(latest_offer_id, [])
            if not linked:
                codes.append('offer_not_found')
            elif len(linked) > 1:
                codes.append('duplicate_offer_id')
            else:
                offer = linked[0]
                mismatched_fields = [field for field in _SOURCE_FIELDS
                                     if str(product.get(field) or '') != str(offer.get(field) or '')]
                if mismatched_fields:
                    codes.append('source_provenance_mismatch')
        if codes:
            issues.append({
                'row_number': index + 2,
                'product_id': product_id,
                'product_name': str(product.get('Product Name') or ''),
                'channel': str(product.get('Channel Username') or ''),
                'status': str(product.get('Status') or ''),
                'latest_offer_id': latest_offer_id,
                'source_post_url': str(product.get('Source Post URL') or ''),
                'codes': codes,
                'mismatched_fields': mismatched_fields,
            })
    return issues
