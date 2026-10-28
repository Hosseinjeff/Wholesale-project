# Operating workflow and record ownership — complete-plan proposal

2026-10-28, Asia/Tehran. This completes the planning proposal for the remaining
handoffs. Where not already confirmed, labels below are recommendations, not new
business decisions. No real order, payment, message or production action is made.

## Operating roles and daily view

Use the existing private WordPress/WooCommerce admin, extending MolaviShop. The
business owner can handle all roles initially and can assign authorized staff later:
intake/catalog review, purchasing, receiving/stock, order coordination, packing/
dispatch and customer support. One person can hold multiple roles. An active item
must have an assigned owner or explicit unassigned queue, last update, next action
and due time when known. These are operating design proposals, not named staff or
invented service-level promises.

Dashboard sections should be linked views of the same records: supplier input and
price-aging alerts; extraction/reference decisions; inventory/expiry/clearance;
orders by payment/procurement/packing/dispatch state; shortages/replacement/refund
work; failed sync/notification jobs; and daily reconciliation. Use current WordPress
roles and WooCommerce APIs and the confirmed owner-choice rules. Avoid creating a
second login, competing WordPress order store, or rewriting existing Elementor pages.

## Whole order lifecycle

1. **Checkout/payment:** customer pays the displayed, server-validated total. Verify
   the payment through the selected existing integration and store the accepted item,
   discount, delivery and payment snapshot. Payment-pending, failed and verified are
   distinct. A browser success page alone does not prove payment. Guest checkout uses
   name/mobile/address with email/account optional. Design secure status/recovery;
   do not assume an SMS provider or promise a message was delivered.
2. **Allocate/procure:** reserve eligible owned lots where available; combine the
   shortfall by compatible supplier/product/pack. Record supplier fixed purchase
   unit, required packs, expected arrival and unallocated surplus. Only verified full
   payment permits purchase commitment. A supplier advertisement is not owned stock.
3. **Receive/consolidate:** compare expected vs received units/condition/expiry per
   supplier, record lot cost/date/quantities and hold missing/damaged goods. Reserve
   accepted units for each order, retain any supplier-pack remainder as stock, and
   keep purchase cost internal. A short line invokes D-024; no silent substitutions.
4. **Pack/dispatch:** check the complete customer order and agreed replacement choices,
   record packing and owner-arranged Tehran handoff/delivery evidence. Do not mark a
   partially packed order fully dispatched or promise dates not configured by the user.
5. **Delivery/aftercare:** record delivery outcome and customer issue/cancellation/
   return requests in Woo order context with assigned next action. Hold affected stock
   pending inspection; only accepted sellable returns re-enter inventory with lot
   provenance. Keep cancellation/return conditions, refund timing and real notification
   channel open for business/operational review; do not invent a policy or send funds.

WooCommerce's native statuses are mapped where semantically correct. Additional
operational fields track the steps above without treating a Woo status change as proof
of actual payment or delivery. Keep official customer order identity, paid amounts,
accepted delivery fee and price snapshots consistent across views.

## Paid shortage and substitution

D-024: check usable owned stock and valid suppliers for the same item at the paid
price; flag owner before accepting a loss. If unavailable, suggest compatible available
alternatives and let the customer choose replacement, missing-line refund or full
cancellation/refund. Keep the original agreed delivery charge when a supplier shortage
removes a line. A replacement never changes a paid order or charges a difference
without explicit customer approval. Record decision, approved totals and settlement
separately; do not mark a refund complete until its actual payment/refund receipt is
verified. Exact substitute matching, refund mechanism/time and contact method remain open.

## Record ownership and bidirectional Sheets — user-confirmed editing

| Data | Recommended authoritative record | Connected surfaces |
|---|---|---|
| Supplier messages, offer history, reference snapshots, review, purchasing and inventory lots | Server-controlled persistent ledger/operations service | Preserve raw/normalized records and intended Sheets workflow; review/edit commands propagate with audit and receipts |
| Pricing settings, product identity, release/availability and quote versions | Server pricing/catalog contract | Sheets and MolaviShop/Woo projections; explicitly define permitted operator overrides and sync direction |
| Customer orders, accepted totals, payment records and fulfillment | WooCommerce using supported CRUD/HPOS-compatible APIs | Server/Sheets reporting and tasks linked by order ID; no second order master |
| Owner price-age/reference decisions and pipeline exceptions | Server durable workflow, with authorized WordPress actions | The existing dashboard plugin; failed actions remain visible |

D-029/D-034: user may directly edit all normalized product-data fields in Sheets
and needs updates in both directions. Products is the current operational row read
by the server; Offers and MessageData remain append-only source evidence. Product
IDs, original supplier posts, source timestamps, import/batch/offer identity and
other provenance fields are protected. Server updates flow into untouched Product
cells; Sheet-only edits remain effective; concurrent edits are held for the owner
to choose in the existing WordPress dashboard. This local adapter is tested with
MemoryStore and synthetic WordPress. A supervised worker polls Products/Shop every
60 seconds by default and queues Woo publication; live Sheet access/cadence remain
unverified.

D-030 confirms preserving both conflicting values and flagging them for user review.
The local field queue uses stable conflict IDs, source revision/value checks, owner
choice and audit note; an open conflict blocks quotes and Woo publication. Newer
source/value observations retire obsolete conflicts. Direct Sheet edits still need
field-level review in connected staging; actual Sheets revision/transaction
limitations must be accounted for rather than claiming atomic cross-system updates.
Test row reorder, same-field races, invalid edits, retry and server-outage recovery
in connected staging.
Competing recovered GAS Inventory log and current Offers/Products/Items must be
mapped/reconciled before selecting one inventory/price authority. Customer purchase
minimums remain independent of supplier pack size. Existing Woo stock and older PHP
store paths must not become separate masters by accident.

## Daily reconciliation and recovery

A single work view reports: incoming vs parsed/reviewed/released/held counts; supplier
price freshness; reference refresh/owner choices; available/reserved/expired batch
quantities; missing catalog/media receipts; paid orders vs procurement/dispatch/aftercare
work; notification attempt vs delivery receipt; and refund requested vs confirmed.
Owners can inspect source, repair or retry idempotently, and see unresolved failures.
Measure source-to-publication and payment-to-first-action without inventing targets.

Reconcile supplier pack purchases and stock movements to paid order allocations;
keep customer line revenue, actual supplier batch cost, delivery charge/subsidy and
remaining stock visible separately. Do not label a provisional estimate as final profit.
Back up and restore persistent storage, mappings, configuration and audit receipts;
replay operations only when side-effect/idempotency status is known. Production job
cutover/retirement is a later explicitly authorized release action.

## Acceptance scenarios across all handoffs

Household: two suppliers, one fixed pack creates surplus, direct guest payment,
allocation/packing, free or paid Tehran delivery, tracking and closure. Retailer:
larger/multiple packages, repeat purchase, possible invoice information without
inventing tax rules. Failure: stale source price, expired reference, multi-item
extraction ambiguity, unavailable paid item with substitute/customer choice,
partial receipt, failed image sync/notification, payment callback retry, dispatch
issue and return/refund. Recovery: duplicate forward/checkout, process restart,
conflicting operator action and backup restore. Use synthetic staging data and
actual connected staging boundaries; existing unit/runtime test counts do not prove
whole-stream readiness.

## Business review items before final baseline

1. Validate the local D-029/D-030/D-034 Sheets reconciliation against the connected
   staging Sheet and Woo projection, including direct edits without a new supplier
   post and the configured publication cadence.
2. Decide whether below-purchase-cost clearance proposals can be submitted for
   owner approval; formula parameters and current market source remain unresolved.
3. Agree delivery/aftercare commitments and refund/return conditions, verification
   and customer communication channels when operationally known. No arbitrary dates,
   fees or payment provider have been set. Physical responsibilities and Tehran
   coverage boundaries are launch settings, not invented assumptions.

These questions can be reviewed as one cohesive plan; internal implementation
choices and parameter tuning need not become separate planning sessions. Customer
requests, full payment before buying, fixed supplier packs, consolidated Tehran
delivery, delivery fee/threshold, age gates and D-024 shortages are already recorded.
