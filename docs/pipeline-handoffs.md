# Whole-flow handoff implementation record — 2026-10-26 onward (Asia/Tehran)

## Current Rezvan source correction — 2026-10-30

Rezvan reference products must come from its WooCommerce Store API. A read-only audit
returned 3,895 products across 39 pages, all with title, SKU, category and positive IRR
price. Commit `3d8a7d2b7eb1f74b7ed72257730d0627f6949a07` is deployed as Railway
deployment `f87efd11-381c-4195-b82e-ce26263b78be`, and the MolaviShop owner-page files
were deployed and read back from that commit. Candidate
`4bf9d367c07dcf1af29e4fc61dfb1eddcb23cbbc4db3ffcdeb63fc987e80f7a9` now contains
3,895/3,895 rows across 39/39 pages with verified coverage and awaits owner review.
The previous PDF/Gemini refresh failed before creating a snapshot; its two older 10-row
drafts are incomplete and inactive. No `ReferencePrices` write or snapshot activation
has occurred. Production supplier-message Gemini is set to `gemini-3.5-flash-lite`.

## Historical production checkpoint — 2026-10-29 (superseded)

The user authorized whole-plan deployment. Commit
`e531b37dbe2f24d4651f0a3578fee863f0de4b96` is deployed successfully to
`wholesale-project-production.up.railway.app` (deployment
`5474efe4-a221-4cbf-8f2a-84f5b757153a`). Railway mounts a 500 MB volume at `/data` and
runs the web process and pipeline worker under Supervisor. The existing MolaviShop
plugin screens and private bridge config are installed; owner identity is WordPress user
3. After the user replaced the Railway Gemini key, the owner-authorized production
refresh fetched the Rezvan PDF and Gemini staged snapshot
`3adf331cfe5877a05b0637774f8dbdb1e02422a1da1cc7437880f62893edcca4` with 10 rows.
The Woo owner page shows an 8-row preview. The candidate remains unactivated; the active
snapshot is null and no `ReferencePrices` projection, customer order or payment occurred.
Next: review the product/package identities and prices in WooCommerce → Reference
Prices, then explicitly approve activation and verify the projection. The service uses
a free plan with sleep enabled, and no separate Railway staging environment exists.
Local verification passes 246 Python tests and 100 disposable WordPress/WooCommerce
runtime assertions; the full connected stream is not signed off.

At that earlier checkpoint, the public WordPress entry point returned a PHP fatal error
because `wp-config.php` was empty. A same-day recovery restored the site; this outage is
resolved and is not the current production state. The old PDF/Gemini refresh described
above is also superseded by the Woo Store API source correction.

The section below is the historical local implementation record; no production change
or end-to-end validation was claimed at its original checkpoint.

## Working local path

Supplier message → committed inbox receipt → saved batch → extraction/price book
→ independent publication job → customer quote → saved order handoff receipt
→ order mirror → Woo-verified paid snapshot → owned-lot reservation → fixed-pack
shortfall proposal in the private Woo dashboard. The existing server pricing and
order sheet contracts remain intact. WooCommerce orders stay on the site; stock
receipts and proposals are stored in the durable pipeline database.

`catalog/durable.py` provides persistent transport jobs with duplicate receipts,
transactional batching, claim leases, restart recovery, delayed retries and visible
failed/review states. Original-source URLs prevent a re-forwarded post from becoming
another intake receipt solely because its destination message ID changed. Text/media
revisions remain separate receipts. Direct-message identities include destination chat.
The batch's original message IDs and album data are preserved. Within-batch retries
no longer append the same raw MessageData rows again.

Extraction rollback is held as needs_review, not published. Publication with missing
image receipts stays pending without re-importing the source. Order handoff is only
acknowledged as written after the sheet writer succeeds; absence/failure returns 503.
Notifications have independent receipts and retry without repeating order writes.
Transport delivery is at least once: a crash after remote send but before local ack
may repeat a notification. There is no claim of universal exactly-once effects.

`includes/order-handoff.php` extends the existing MolaviShop plugin: classic and
block checkout share the handoff, require a matching order-ID/written receipt, retain
pending/failed status in Woo order meta, and retry using WooCommerce Action Scheduler
(or WP-Cron fallback). Five retries are allowed; exhaustion adds an internal order
note for operator attention. Retry payload is frozen on first attempt. The local
plugin also listens to WooCommerce's `payment_complete` hook. After Woo marks an order
paid, it sends a second durable mirror snapshot with Woo status, payment method,
transaction reference, paid timestamp, product SKU and supplier offer ID. Matching
server receipts and Action Scheduler retries are required before the payment mirror
is marked written. Cancellation, refund and failed-status snapshots reach the server
and release only open reservations. Live gateway amount/callback and complete
fulfillment policy remain unverified.

`catalog/order_fulfillment.py` reserves eligible owned lots by expiry date after the
verified paid snapshot is mirrored. It plans fixed supplier packs for the exact
shortfall and carries minimums, multiples, cost and expected surplus. Missing offer
reference or terms stays in owner review. The Woo dashboard accepts lot receipts with
purchase cost, dates and a required audit note, then accepts reviewed supplier terms.
The server ties terms to the source offer ID on the paid line and records the owner
decision. It never submits a supplier purchase. Private routes reuse the existing
MolaviShop owner binding, bridge token, actor header and WordPress nonce/receipt checks.

## Activation contract — not performed

- Set PIPELINE_DB_PATH to a private SQLite file on persistent server storage. The
  parent directory must exist. Payloads include private source/order data; DB files
  are mode 0600. Backups and retention still require operational policy.
- Use the server Sheets writer. Durable mode is opt-in; without the DB setting,
  existing intake still uses its memory buffer. The no-sheet-write order success
  bug is independently fixed in the draft even outside durable mode.
- Supervise `python tools/run_pipeline_handoffs.py` beside the web process. `--once`
  processes one available job. A web timer is a convenience, not the recovery worker.
  Do not enable the environment setting without supervising the worker and storage.
- The durable worker polls Products and Shop every 60 seconds by default and queues a
  publication when the current Sheet projection changes. Set `SHEET_SYNC_POLL_SECONDS`
  to 30–3600 seconds if a different cadence is needed. This requires the live Sheet
  writer and persistent queue; synthetic tests do not verify live polling.
- This prototype targets the existing single-worker service. Lease expiry is 15 min;
  jobs exceeding it still need heartbeat checks before multi-worker scaling. It is not
  a distributed transaction with Google Sheets. A partial external write persists a
  publication hold, moves to owner review, and replays with stable IDs only after an
  explicit reconciliation note/action. Legacy/GAS writers still bypass the server's
  cross-process locks and require controlled cutover.
- The user has explicitly requested whole-plan deployment. This draft remains short
  of integrated staging and production readiness; do not deploy a partial release.

## Evidence

112 Python tests pass (64 existing, 35 reference, 13 new handoff/replay tests).
The synthetic replay uses the real parser, MemoryStore price book and quote engine,
then an order mirror, with external publication/transport replaced by test adapters.
It covers restart, duplicate/re-forward, expired worker lease, partial publication,
extraction rollback, unconfirmed order, notification failure and HTTP receipt retries.
No production tokens were used for the suite.

19 assertions pass under disposable WordPress 7.1.3/WooCommerce 11.2.1 using synthetic
users/orders and mocked HTTP: 14 reference-dashboard assertions plus 5 checkout/
retry assertions. The database, container and internal Docker network are removed.
This is real PHP/WP execution, not a visual browser/Elementor/Wordfence compatibility
pass. Evidence is in pipeline-offline-test-evidence.txt and
reference-dashboard-runtime-evidence.txt.

## Remaining whole-project work

1. Validate the locally implemented recovered GAS category/history scale fallback,
   AI confirmation, and scheduled Woo API candidate review against connected
   staging, then replay the reference-to-catalog path. Snapshot-scoped owner matching
   and item-level correction are locally implemented as described below.
2. Validate lot receipts, sheet ownership and paid-order reservations with persistent
   staging storage. Railway's pipeline database and worker are deployed on persistent
   `/data`; the dashboard lot ledger is not mirrored to Sheets, and connected stock/order
   recovery still needs validation.
3. Validate the paid-order follow-up described below against connected staging and
   the installed checkout/gateway. The local workflow does not send customer messages,
   issue refunds, charge additional payments, place supplier purchases or arrange a
   live delivery.
4. Add a unified view for held/failed pipeline work and application-level intake
   authorization. Existing command/prompt replies still use their old synchronous
   path; durable supplier intake does not fix those.
5. Complete connected gateway/checkout staging, then household/retailer roleplay,
   recovery/rollback and visual review. The mockup stays unapproved and gateway
   selection stays deferred.

## 2026-10-28 paid stock and procurement continuation

`/shop/order` now mirrors paid order lines before acknowledging the handoff and
creates a durable fulfillment plan. Owner-only `/pipeline/stock-lots` receipts record
lot/SKU/supplier, received/production/expiry dates, unit cost and an audit note.
Reservations use FEFO and are idempotent. Cancellation/refund/failure updates release
reserved units; fulfilled units stay committed. Owner-entered fixed-pack terms produce
a cost/surplus proposal tied to the order's source offer ID. The Woo dashboard lists
lots, availability, shortfalls and proposal inputs. No purchase is sent. Stock records
are in SQLite only; production persistence, Sheets ownership, order revisions/returns
and customer fulfillment remain open.

The full offline suite passes 196 Python tests. The disposable WordPress 7.1.3 /
WooCommerce 11.2.1 runtime passes 70 assertions, including owner authorization,
stock receipt retries/conflicts, paid-order reservations, terminal status propagation
checkout-time SKU/offer freezing and no-purchase proposal behavior. Gateway, Sheets, Elementor/Wordfence and connected
staging interactions remain synthetic/unverified.

No scene is marked complete. Continue at whole-handoff level; avoid reopening the
previously deferred micro-policy question about every reference/supplier price gap.

## 2026-10-28 paid-order follow-up continuation

`catalog/order_operations.py` adds an idempotent owner-audited workflow per paid Woo
order. The private dashboard can assign an operator and next action; record a supplier
purchase that the owner already placed; record unavailable/partial deliveries; and
reconcile a later supplier receipt into the lot ledger. Supplier purchase proposals
remain advisory and never place a purchase.

For an unavailable customer line, the dashboard can shortlist similar products only
when current owned lots cover the required units. The server rechecks stock and the
current product price range before saving a replacement proposal. The owner records
the customer's accepted replacement, partial refund or full cancellation after
contacting them. A higher replacement amount requires recorded customer consent and
the actual additional-payment reference. Refund requests are capped against the
original paid total, and the owner records an actual Woo/gateway refund reference
before packing or closure. An order cancelled after a supplier purchase keeps that
purchase's receipt visible; arrivals become unreserved store stock.

After all affected lines have an accepted resolution, required refunds/additional
payments are recorded, and the order is packable, the same owner dashboard records
packing, owner-arranged dispatch/tracking, delivery, aftercare and closure. Native Woo
admin capability checks, nonces, the existing owner binding, actor header and
matching server action receipt protect the controls. Tests cover the server routes,
stale/unavailable alternatives, refund caps, customer consent, supplier arrivals
after cancellation, and the rendered Woo screens.

At the original local checkpoint, the order and inventory ledgers were SQLite-only and
production had no persistent volume or bridge. The current deployment above now stores
them on `/data`; they are still not mirrored into Sheets. Live Gemini extraction now
succeeds, while checkout, payment callback, customer contact and
refund execution remain unverified. Exercise the checkout-to-aftercare flow in a
connected staging boundary before claiming full release readiness. Customer contact and
refund execution remain owner actions until their channels and installed gateway
behavior are validated.

## 2026-10-28 reference item review continuation

`reference_import/matching.py` now ranks owner suggestions with the recovered
name/brand/category/variant/package-count/volume/weight weights and .35 inclusion
threshold. Scores are suggestions only; a score never selects a reference. The private
Woo submenu `تطبیق کالاهای تأمین‌کننده` lets an authorized owner link the current offer
to a reference and explicitly choose unit or package price, or confirm no match and
keep the item held. Decisions are recorded in the reference SQLite store against the
current Offer ID and snapshot, require a note, support expected-decision corrections,
and pin future exact-identity matches only while the same snapshot remains active.

Offers stay append-only. An owner-confirmed package price is divided by its confirmed
pack count only in the current Products projection; the pack minimum is retained and
the original supplier number stays in Offers. A no-match decision continues to hold
the product. Successful corrections change the Products projection and queue the
existing serialized Woo publication worker. Ambiguous exact matches and ambiguous
unit/package scale are now withheld until owner review.

The new route requires the existing private bridge token, owner actor, active usable
snapshot, persistent reference store, durable pipeline database and server Sheets
writer. Its dashboard uses the existing MolaviShop owner/capability checks, WordPress
nonces and matching server receipts. 202 offline Python tests and 74 disposable
WordPress/WooCommerce assertions pass at this earlier checkpoint. Live reference
refresh, connected Sheets and an end-to-end staging replay remain open. A read-only
Sheets probe in the current executor stopped
before an API request because that executor's service-account JSON failed parsing;
this does not establish the validity of Railway's separate redacted value. No sheet
write or production change occurred.

## 2026-10-28 local parity continuation

The local server now ports recovered category/history price-scale fallback and the
owner AI-confirmation lifecycle. A crash-aware 24-hour PDF/Gemini worker stages a
versioned candidate; the private Woo dashboard supports refresh status, candidate
review, activation and verified projection to `ReferencePrices`. Price-scale tests
use synthetic references/history. Snapshot tests use MemoryStore; no live Gemini
request or Google Sheets read/write was made. Full local discovery passes 222 Python
tests, and the disposable WordPress 7.1.3/WooCommerce 11.2.1 runtime passes 83
assertions. Connected service credentials/owner bridge, persistent Railway storage,
legacy-writer cutover, active-plugin/browser compatibility and full checkout-to-aftercare
staging remain release gates.

## 2026-10-28 operator/clearance continuation

The unified private Woo operations page now summarizes durable held/failed jobs,
publication holds and open Sheet conflicts without exposing job payloads. It links to
the existing owner review screens. Inventory receipts now retain purchase date; supplier
purchase actions record it and receipt processing checks the batch date against that
purchase record.

The local clearance proposal path now has an authenticated owner API, a private Woo
screen, lot-receipt-hash checks, an idempotent audit ledger, same-basket normal/current
market input and a candidate interpolation formula. Missing purchase data, ineligible
lots and stale market dates are held. It never writes Woo customer prices. Formula
weights, the near-cost amount, scheduled recalculation, proactive alerting, below-cost
override and Sheets mirroring remain unresolved or unimplemented. `docs/clearance-pricing-design.md`
records the candidate model and boundaries.

At this local checkpoint, 235 offline Python tests and 93 disposable WordPress
7.1.3/WooCommerce 11.2.1 assertions passed. Current validation is 268 Python tests and
104 disposable runtime assertions. The Woo Store API candidate is live and staged; no
activation or Sheets write occurred. Sheets reconciliation, browser compatibility and
whole-stream staging remain unverified.

### 2026-10-28 audited inventory continuation

The owner Woo dashboard now supports lot count corrections, damage/expiry deductions,
quality/record/supplier holds and inspection-based release. Each action records the
owner, reason, note, timestamp and reviewed lot revision in an append-only SQLite event
ledger. Stale screens conflict; open reservations prevent a hold, and adjustments cannot
reduce reserved or fulfilled units. Held lots cannot be allocated to new orders. The Woo
bridge verifies the returned event receipt against the exact reviewed revision.

Offline validation passes 241 Python tests. The disposable WordPress/Woo runtime passes
97 assertions. These are synthetic checks and do not test connected Sheets, gateways,
persistent Railway storage or production stock. Returns, reservation expiry/order
revisions, Sheets mirroring and connected staging remain follow-up work.
