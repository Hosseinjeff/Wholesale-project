# Whole-plan release and later-iteration backlog

2026-10-31, Asia/Tehran. The user asked to execute/deploy the plan as a whole and
record any remaining items for later iterations. The server, production volume, and
existing MolaviShop plugin integration are deployed. The current Rezvan reference source
is its WooCommerce Store API. After repeated transport failures, commit `a157ee5` and
the updated owner UI are deployed; a full retry staged candidate
`2976f2004a643e5546dc242ec876607dad1fd03d2fb6ee7cd124881d30497380` with verified
3,919-row coverage over 40 pages. It is inactive and awaits owner review; the active
snapshot remains unset and no `ReferencePrices` write occurred. The two older PDF/Gemini
drafts are incomplete and inactive. This file distinguishes incomplete release
connections from optional business settings; mockup v2 remains unapproved and no
credentials are stored.

The 2026-10-30 text below records an earlier deployment checkpoint; current production
reference status is recorded above and in `pipeline-readiness.md`.

**Historical production incident — 2026-10-29:** WordPress previously failed during
bootstrap. A later same-day live recheck found the site and Woo storefront responding
normally; this incident is resolved. The user has made the site public, and Shop page 7
is now the static front page. Current live gaps are recorded in `pipeline-readiness.md`.

## Cross-cutting requirement — current site plugins (D-032)

Coordinate with the actual active WordPress plugins/configuration, including
WooCommerce, MolaviShop, Elementor, payment and security plugins. Reuse supported
capabilities rather than creating competing order/admin/catalog systems. Verify
classic/block checkout, HPOS, payment callbacks, plugin interactions and cache/security
in isolated staging before rollout. Plugin activation alone does not prove capability
or payment readiness. Do not disable existing plugins or select a new gateway merely
to make integration easy.

## Whole-release integration still required

1. Review the staged Rezvan WooCommerce API/reference candidate, explicitly approve
   activation, then verify the `ReferencePrices` projection in production. The full
   fetch and verified-coverage staging path have now succeeded. Local
   code now includes recovered category/history price-scale fallback, durable AI owner
   confirmations, a crash-aware 24-hour Woo API refresh,
   staged candidates, Woo owner review and verified projection to `ReferencePrices`.
   Weighted match suggestions, snapshot-bound owner overrides, item/price-role review
   and guarded publication are connected to opt-in durable intake. The append-only
   Offers record preserves the supplier's original values; an owner-confirmed carton
   cost is normalized only in the current Products projection. Unmatched or uncertain
   items stay held. A missing/stale snapshot
   waits for a protected dashboard choice, as covered by synthetic tests. An
   uncertain batch write now creates a persistent publication hold; the private Woo
   owner dashboard records reconciliation and explicitly queues that batch for replay.
   Reference activation and app publication share cross-process locks. This cannot
   fence legacy/GAS writers that bypass the server locks, so cutover remains required.
   The previously deployed worker fetched the official PDF and Gemini staged two
   incomplete 10-row drafts. Those drafts remain historical and cannot be reviewed or
   activated. Review title/price/package interpretation and treat stale/missing refreshes
   according to the agreed owner-review gate.
2. Validate the local bidirectional Products-sheet adapter in connected staging.
   It handles every normalized non-provenance product column, performs three-way
   reconciliation on supplier updates, preserves source posts, records owner-visible
   conflicts in SQLite, and blocks conflicted products from server quotes/Woo. Owner
   resolution is integrated with the existing Woo dashboard. The supervised worker
   polls Products/Shop every 60 seconds by default (configurable from 30 to 3600) and
   schedules durable Sheet-to-Woo publication. Production storage and worker are
   deployed, but verify row-level receipts, cadence, stale/invalid cells, row moves,
   write races and outage recovery against a connected Sheet before sign-off.
3. Continue owned-stock and clearance work. Owner-only Woo screens record append-only
   lot receipts with purchase/production/expiry dates, cost and audit note; supplier
   purchase date is retained and checked at receipt. Paid Woo snapshots reserve eligible
   lots FEFO, and fixed-pack shortfalls become owner-noted proposals with supplier cost
   and expected surplus. Cancellation/refund/failure releases only open reservations.
   A private owner screen/API now audits clearance price proposals from lot dates/cost,
   same-basket normal price and today's comparable market quote; no customer/Woo price
   is changed. Owner-only stock count corrections, damage/expiry deductions, lot holds
   and reviewed releases now append revision-bound audit events; open reservations block
   holds and reserved/fulfilled quantities cannot be reduced. Held lots are excluded
   from new allocations. These ledgers remain SQLite-only and are not mirrored to Sheets;
   the Railway production volume is present but no staging store exists. Multi-supplier choice, actual purchase
   completion, reservation expiry, order revisions, customer-return disposition, formula
   approval, proximity threshold, scheduled recalculation and proactive near-cost alerts
   remain.
4. Validate supplier-price freshness against actual Sheets edits and every payment
   path. The local draft now binds verification to the latest offer and supplier cost,
   uses the source post timestamp, shows day-7 warnings in the Woo owner dashboard,
   blocks quotes and Woo purchases at day 14, and permits audited owner verification
   followed by guarded republishing. Continuous Sheet-edit detection and connected
   payment staging remain open. The native Woo delivery method is drafted; isolated
   Woo checks cover the 250,000/5,000,000-toman boundary, discounted subtotal and
   IRT/IRR conversion. It is not enabled in a live zone; Tehran coverage boundaries,
   actual tax/shipping-zone settings, multi-package handling and block/classic full
   checkout still require verification. Current method rejects multi-package carts
   pending a consolidated-shipping design rather than charging multiple fees. A
   supplier-evidence ledger prevents repeated old source revisions from refreshing
   age. Its server/Woo wiring is deployed with durable production storage and a worker.
5. Validate the locally implemented order ownership, procurement tracking, customer
   choice/refund receipts, consolidated packing, dispatch, tracking and aftercare in
   connected WordPress/WooCommerce staging. The `payment_complete` handoff mirrors
   payment and exact supplier offer provenance, reserves lots, and creates only a
   reviewable supplier pack proposal. Cancellation/refund/failure updates release
   open reservations. Verify the installed gateway callback/full amount and customer
   contact/settlement channel before enabling those paths. Check existing site
   payment/substitution features before extending them.
6. Complete operational resilience: user-visible alerts/recovery, backups, migration
   and synchronization checks. Railway has one production environment, a 500 MB
   persistent volume and a sleep-enabled service; there is no isolated staging target.
7. Complete responsive customer/operator review, connected synthetic staging replay,
   household then retailer roleplay, failure/recovery/rollback and mockup review.
   Visual mockup v2 remains unapproved. A whole-plan release is not ready yet.

## Business details for later iterations or release configuration

- Clearance formula weights/windows, proximity-warning amount and proactive channel;
  near-cost warning and owner approval before below-purchase-cost publication are
  confirmed. Missing data must not silently generate new prices.
- Actual market-price source/equivalent units, stock reservation duration, refund/
  returns conditions and timing, and guest status/recovery channel. Do not invent
  fees, automatic refunds, SMS providers, tax rules or delivery promises.
- Live Google Sheets reconciliation, automatic detection/publication cadence and
  field-level direct-edit audit coverage remain staging work; all product-data fields
  are editable by user decision D-034, with source/provenance metadata protected.
- Tehran coverage boundary and operational staff assignments; fixed delivery and
  free-delivery amounts are already decided and must not be re-asked.
- Payment gateway selection is not an excuse to assume the installed plugin is
  functioning; verify the existing integration in a safe test context before checkout.

## Current test/readiness evidence

- 246 local Python tests pass using synthetic inputs/mocks, including every editable
  Products field, two-sided supplier/Sheet conflict and resolution, quote/Woo holds,
  supplier price revision, warning/block, owner verification, delivery boundaries,
  owned-lot reservations and fixed-pack proposal paths.
- 100 isolated WordPress 7.1.3/WooCommerce 11.2.1 assertions pass, including owner-only
  publication recovery, order retries, lot receipt/expiry UI, paid-order proposals,
  delivery-zone boundaries, supplier-out-of-stock projection, operations view and
  clearance proposal/correction and audited stock adjustment/hold screens. Browser/Elementor/
  Wordfence interactions remain unverified.
- The paid-order owner dashboard now covers assignment, supplier confirmation/receipt,
  substitution and customer decisions, refund/payment receipts, packing, dispatch,
  aftercare and closure. Its SQLite database is persistent on Railway `/data`, but the
  connected gateway, customer contact, Sheets mirroring and real order flow are unverified.
- Railway deployment `e531b37` is healthy; web and worker run against `/data`. The live
  candidate is staged in Woo for review; activation and `ReferencePrices` projection
  have not happened. The separate `FollowUP-bot` pending approval was left untouched.
- Historical test evidence and the partial live sample are not whole-stream staging or
  proof of live data correctness. Resolve release integrations above before claiming
  full readiness.
MD

## Additional local execution progress

- `catalog/inventory_lots.py`: persistent lot receipts/reservations and fixed supplier
  pack planning; concurrency, early-expiry allocation, cancellation and duplicate
  receipts exercised. It now connects to the owner Woo dashboard, the paid order
  handoff and fixed-pack proposal workflow. It remains local SQLite, is not mirrored
  to Sheets or live Woo stock, and cannot submit purchases. Reservation revisions,
  lot holds/returns/adjustments and confirmed FEFO business policy remain.
- `catalog/supplier_freshness.py`: persistent source/manual price evidence with
  append-only event history and revision-bound status checks; re-importing the same
  old post does not refresh its timestamp. It is now used by the server catalog/quote
  path, owner dashboard and Woo publication/purchasability metadata. Sheet edit event
  capture and connected payment staging remain incomplete.
- `reference_import/matching.py`: exact candidate and price-role hints; contradictory
  or ambiguous matches enter review and supplier prices are not overwritten. This
  remains owner-reviewed rather than auto-selecting from a score. Recovered weighted
  suggestions, snapshot-bound overrides, item-level review, category/history price-scale
  fallback and AI confirmation now have local server/Woo implementations. Live reference
  refresh, Google writes and connected staging are still unverified.
- `shop-wp/molavi-shop/includes/delivery.php`: native Woo shipping method available
  for explicit assignment to the proper zone, not automatically enabled. Woo checks
  cover IRT threshold, discounted subtotal and IRR conversion. It uses the existing
  cart; multi-package orders intentionally produce no rate pending consolidated
  package integration. Full checkout, live zone, tax and plugin interactions are
  unverified. This is not a production setting change.

The user requested relying on existing site documentation instead of unnecessary
site-file inspection. Use targeted tests and the audit evidence (D-033).

## Paid stock/procurement and Woo shipping-zone continuation

- `catalog/procurement.py` calculates required supplier packs and surplus for
  grouped customer demand using explicit pack/minimum/multiple terms. Paid Woo orders
  now reserve received owned lots and create owner-reviewed pack proposals for any
  shortfall. An owner must record the current terms and note; the proposal never
  submits a supplier purchase. The lot ledger has no live Sheets mirror; Railway
  production storage exists, while an isolated staging store does not.
- Woo-native delivery method was additionally tested through actual synthetic
  WooCommerce shipping-zone selection, including discounted subtotal, threshold
  boundary and IRT/IRR conversion. It is not installed or configured on the live
  site. Zone boundaries, taxes, multi-package carts, classic/block payment checkout
  and active security/Elementor interactions remain unverified.
- User requested relying on documentation rather than unnecessary site-file reads;
  targeted tests here used only synthetic local WordPress/WooCommerce data.

## Reference-to-intake integration continuation

`REFERENCE_STORE_PATH` plus `REFERENCE_OWNER_IDS` enables the local reference gate.
The authenticated `/reference/decisions` route uses a separate `REFERENCE_BRIDGE_TOKEN`
and WP actor allowlist. With a missing or stale snapshot, durable intake waits rather
than auto-proceed. An owner-approved stale snapshot carries a warning and its ID into
the offer rows. Exact compatible candidate and scale hints never overwrite supplier
prices; unmatched items are marked needs_review. A changed snapshot during batch
processing leaves a persistent hold that blocks application publication. The same
hold survives worker death; after its lease expires, the batch goes to owner review.
The Woo dashboard records reconciliation and explicitly requeues that batch for
idempotent replay before its publish job is created. App publication and reference
activation are serialized across server processes. This guard cannot stop an old
deployment or GAS writer that bypasses the server locks; rollout needs a controlled
worker cutover. Item-level correction UI and full GAS matching/AI contracts remain
release work. Feature-disabled behavior remains legacy and is not parity.

Synthetic replay exercised a stale snapshot → waiting → authenticated owner choice
→ intake with snapshot provenance and unchanged supplier price. 155 offline tests
pass, but the test uses MemoryStore and simulated bridge, not live Sheets or a
complete customer order. No real offer, payment or production action occurred.
