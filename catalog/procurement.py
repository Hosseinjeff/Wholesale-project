"""Supplier fixed-pack purchase planning; no purchase is submitted by this module."""
from collections import defaultdict


def _whole(value, *, zero=False):
    if isinstance(value,bool) or not isinstance(value,int) or value<(0 if zero else 1):
        raise ValueError('non-negative or positive whole number required')
    return value


def plan_purchase(lines, offers, owned_available):
    """Group demand, consume owned units once, and round shortfall to supplier terms.

    One selected supplier offer per SKU is supported. Missing/conflicting supplier
    pack terms require review; ambiguous multiple supplier choices cannot consume
    the same owned units twice. The result is a proposal, not a purchase or reservation.
    """
    quantities=defaultdict(int)
    for line in lines:
        sku=line.get('sku');order_id=line.get('order_id')
        if not isinstance(sku,str) or not sku or not isinstance(order_id,str) or not order_id:
            raise ValueError('each line needs order and sku identity')
        quantities[sku]+=_whole(line.get('units'))
    offer_map={}
    for offer in offers:
        sku=offer.get('sku');supplier=offer.get('supplier')
        if not isinstance(sku,str) or not sku or not isinstance(supplier,str) or not supplier:
            raise ValueError('supplier and sku required')
        if sku in offer_map:raise ValueError('multiple offers for sku require explicit selection')
        offer_map[sku]=offer
    result=[]
    for sku,demand in quantities.items():
        offer=offer_map.get(sku)
        if not offer:raise ValueError('selected supplier terms missing')
        pack=_whole(offer.get('units_per_pack'))
        price=_whole(offer.get('pack_price_toman'))
        min_packs=_whole(offer.get('minimum_packs',1))
        multiple=_whole(offer.get('pack_multiple',1))
        available=_whole(owned_available.get(sku,0),zero=True)
        stock_used=min(demand,available);shortfall=demand-stock_used
        required=(shortfall+pack-1)//pack
        if required:
            required=max(required,min_packs)
            required=((required+multiple-1)//multiple)*multiple
        purchased=required*pack
        result.append({'sku':sku,'supplier':offer['supplier'],'customer_units':demand,
                       'owned_units_allocated':stock_used,'shortfall':shortfall,
                       'supplier_packs':required,'purchase_cost_toman':required*price,
                       'supplier_units':purchased,'expected_surplus_units':purchased-shortfall})
    return result
