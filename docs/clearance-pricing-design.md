# Batch clearance pricing — owner proposal implementation

2026-10-27 proposal; local owner-review tooling added 2026-10-28. This remains a
proposal, not an owner-approved or customer-facing pricing formula.
D-026–D-028 require the normal basket formula as the baseline and a lower price
for eligible clearance batches, informed by purchase date, production date if
available, expiry, purchase cost and current market price.

## Inputs and calculation

For each batch, retain product/unit, purchase date and cost, production date if
known, expiry, available/reserved quantities, and market price for an equivalent
product/unit with its source and observation time. The existing basket formula
provides the normal unit price for the customer's current basket.

1. Calculate time held since purchase and time remaining until expiry. If the
   production date is available, calculate the proportion of total shelf life
   remaining too. Purchase age and shelf-life age are distinct measures.
2. Check configurable clearance eligibility. Missing or contradictory dates,
   ambiguous units, unavailable/stale market data and held/expired stock require
   an explicit review or approved fallback. Do not invent missing dates or treat
   an unchanged old supplier post as newly verified market evidence.
3. As a proposed starting model, set `starting_price` to the lower of the normal
   basket price and comparable market price. Calculate a configurable urgency
   between zero and one using the time-in-stock and expiry measures. Interpolate
   from the starting price toward a permitted floor as urgency rises:

   `suggested_price = starting_price - urgency × (starting_price - floor)`

   The user wants notification before clearance reaches purchase cost (D-031). A
   below-purchase-cost clearance price requires owner approval before publication.
   Exact proximity threshold, other cost/margin treatment, clearance window and
   urgency calculation remain to be agreed. If normal or market price is already
   below purchase cost, alert/review instead of silently authorizing a loss.
4. Round consistently with the existing pricing rules. The offered clearance
   price must be lower than the normal basket price at the same basket conditions.
   At a boundary where rounding produces the same price, keep the normal price or
   request review; do not present an equal price as a clearance discount.
5. Display the correct current/maximum discount and final price without counting
   any saving twice. Recalculate as the basket changes. Batch-specific quantities
   and relevant expiry/condition must be clear to the customer. Calculate free
   delivery from the resulting goods subtotal and snapshot the accepted prices.

This is one reviewable approach, not a claim that the user chose interpolation,
thresholds, a market provider, loss permission or discount display mechanics.

## Synthetic example

A batch has a normal basket price of 90,000 toman, an equivalent market price of
100,000 and a purchase cost of 70,000. If the configured expiry/holding rules
produce urgency 0.5 and purchase cost is the permitted floor, the proposed price is
80,000 toman. At urgency 0.75, it is 75,000. These values illustrate the arithmetic;
they are not suggested time windows or live settings.

## Whole-flow consequences

The inventory ledger must retain batch dates, quantity, cost and reservations;
procurement must use the supplier's fixed purchase packs; checkout must use actual
sellable quantities and the same calculation as the server; the WordPress operator
dashboard must show the basis and exceptions. A paid order retains its accepted
price. If an item then becomes unavailable, use the agreed customer substitution/
refund workflow and retain the original agreed delivery charge.

Acceptance must cover different batch costs/expiry dates, missing production dates,
stale market prices, expiry boundaries, mixed batches, basket changes, delivery
threshold, below-cost cases and a paid-order snapshot. A normal-price-versus-clearance
comparison must use identical basket conditions. Validate with connected staging
and a whole-stream roleplay before any production action.

## Local implementation status — 2026-10-28

`catalog/clearance_pricing.py` calculates a proposal and stores an idempotent,
private audit record. An owner-only `/pipeline/clearance-proposals` API and private
WooCommerce screen collect the batch receipt hash, same-basket normal unit price,
current comparable market price/source/date, and review note. A stale lot receipt,
missing purchase date, unmarketable/expired lot, or non-today market observation is
held. The screen shows recent calculations and flags the missing proximity threshold.
No Woo product price is written and no customer sees a clearance amount.

The implemented *candidate* curve computes holding urgency as `days held / (days held
+ days remaining)`. When production date exists, it averages that value with product
shelf-life urgency computed from production date. Clearance begins at urgency 0.5.
It interpolates from the lower of normal-basket and current-market price toward
purchase cost, clamps at cost, and forces a price below the normal unit price. This
arithmetic exists to make a reviewable result; its weights, start point, market
source/comparability and threshold are not approved policy. Below-cost publication
requires an explicit owner approval and is not implemented.

`CLEARANCE_NEAR_COST_ALERT_TOMAN` is optional and intentionally blank in the sample
environment. When unset, the dashboard says the early warning cannot be calculated;
the system does not invent a threshold. The warning is visible in Woo admin only after
an owner requests a calculation. Scheduled recalculation and proactive notifications
are still required before this can fulfill the ongoing notification requirement.
Inventory lots remain local SQLite data and are not yet mirrored through Sheets.
