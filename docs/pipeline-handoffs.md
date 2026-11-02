# Whole-flow handoff implementation record — 2026-10-26 onward (Asia/Tehran)

## November 2 audit response — receipt reconciliation and review safeguards

### Dashboard review rollout status — 2026-11-02

The owner-review UI and its WordPress table migration/write path are on `main`; the
plugin uploader verified commit `78b3188ed0b377bf9a573ccec158cee516f7d334`. Table
creation and writes were not verified. The UI is compatible with the
currently live conflict endpoint and disables blind batch retry when the API omits its
source context. The required Railway API change is also on `main`, but production still
runs `8e2ae9a32d4abc3c66e0b0ba06cf514db8d07f9d`; no new build is pending. Reconnecting
the existing service to its same repository was rejected by Railway's automatic review
because it would mutate live source configuration across environments. No source
settings or database rows were changed. Approval is needed before using that path; until
then the source-linked publication review is not fully active.

The operations dashboard now offers an owner-only, read-only receipt reconciliation
report. It joins durable intake receipts to `MessageData`, extracted/reviewed `Offers`,
current `Products`, publication jobs, and the public Woo Store API. Per-product details
include status, supplier and customer prices, unit/package basis, reference approval and
price-change evidence, Woo SKU/visibility, and the original supplier post link. The
report does not show source message text or alter queues, Sheets, or WooCommerce. Older
receipts have no receive timestamp; new receipts acquire timestamps after the database
migration. The report marks missing Woo rows as unchecked when the catalog read is
unavailable or truncated.

Bulk dismissal now previews each selected offer's price, unit/package basis, reference
match and the publication/status effects. Individual decision history is visible, and a
prior “not a product” decision can be superseded by a new reviewed confirmation. The
historical decision remains in the ledger.

`tools/evaluate_supplier_extraction.py` measures exact product identity, source price,
price basis, package count and extra products against a private owner-labelled JSONL
corpus. It makes one Gemini request per case and prints aggregate results only. It has not
been run because no labelled corpus was supplied; AI accuracy remains unmeasured.

The public Railway `/api/shop` and `/api/cart` now return customer quote fields only. The
WordPress storefront no longer serializes internal discount floors/ceilings or margin
curve parameters into page markup or localized JavaScript. The server remains the source
for live cart prices.

The November 2 source changes are released to `main` and production via the release
workflow recorded in `site-change-workflow.md`; deployment and public response checks are
recorded there after rollout. No product, order, queue decision, or payment record was
modified during release.

## WooCommerce-native product and order context — deployed 2026-11-01

Owner workflow context is now surfaced directly in WooCommerce. The Products list shows
supplier and supplier-price freshness and can be filtered by price state. The product
editor's MolaviSale tab shows source metadata, including private supplier cost, and links
to matching and price review scoped to that product. The Orders list shows the
MolaviSale handoff state and links to the Woo order and its focused preparation screen;
the Woo order editor has the same shortcut. Actions on a focused order return to that
order and display a success notice.

Source-owned product data stays read-only in the product tab and is changed through the
existing review path. WooCommerce order records stay the order authority; the focused
screen reuses existing server-side order operations. Deployment and file read-back are
recorded after the release below. No production product/order records were edited for
this integration.

The same WooCommerce owner entry point now presents the existing screens as horizontal
tabs. Each tab loads its section content inline for extraction, reference review, product
matching, freshness, publication, Sheet conflicts, inventory, clearance pricing, or orders.
The tabs are keyboard accessible, scroll on narrow screens, preserve their content when
switched, and keep the selected tab in the URL. Direct screen links remain available for
WooCommerce product/order context and as a fallback.

Deployment record: commit `0a3e7f27c12f419d175645592166913e2c9f8db1` is on `main` and
was uploaded through the WordPress plugin release script. The uploader read back and
verified `includes/owner-workflows.js`, `includes/pipeline-holds.php`,
`includes/woocommerce-admin.php`, and `molavi-shop.php`. No Railway service, product,
order, payment, or owner review decision was changed. The admin screens were not opened
in a live browser after deployment.

Tabbed workspace deployment: commit `b4626edf118cc2fbbc4f8753d1bbce66ecf0724a` is on
`main`; the WordPress uploader verified by read-back `includes/operations-dashboard.php`,
`includes/owner-workflows.css`, and `includes/owner-workflows.js`. No owner action or
production product/order record was changed during this deploy. The live tab experience
was not opened in a browser after deployment.

## Bulk dismissal and uncertain-item suggestions — deployed 2026-11-01

Commit `3ed092cbf6c2f2ac669b8532837c317d3429d72c` is on `main`. Railway deployment
`6619c66b-0bb7-4442-a215-bf38dd041c99` reached `SUCCESS`; `/health` returned
`healthy`. The Railway service now explicitly follows the repository's `main` branch.
The owner plugin files were uploaded from the same commit and read back successfully.

In the extraction screen, the owner can dismiss every message currently awaiting
re-extraction with one confirmed action. A durable record uses the source message's
identity and content hash, so dismissal excludes that message from later re-extraction
without deleting or rewriting its original content. The preview count and digest are
checked again under the same lock used to enqueue re-extraction. Open AI cases can also
be selected individually or all at once and marked “not a product.” A note is optional
for that dismissal; confirming extracted details still requires a review note.

Reference item review now displays the six highest ranked candidate products, including
their similarity score and unit/package prices. A candidate remains a suggestion until
the owner selects and submits it. The existing manual entry is retained and accepts an
exact normalized product name or reference ID; ambiguous names are held for selection.
No owner decision was submitted while deploying, and the new interactions have not been
exercised against live messages or reference cases.

## Saved-message re-extraction workflow — deployed 2026-11-01

The owner extraction screen now previews and can queue either messages still awaiting
AI processing/review or all eligible saved text messages. Previously owner-decided,
not-a-product, and already-staged messages are excluded and counted in the preview.
Each source message is re-extracted by the current configured AI rules as its own
durable job; source identity and content are checked again before processing. The
owner can cancel queued work; one already running may finish.

Re-extracted offers are appended to `Offers` as owner-review cases. This path does not
rewrite `MessageData` or the current `Products` book, and does not queue publication.
The existing owner review shows the original message and timestamp. Confirming a
historical result also requires an explicit owner check that its price and stock are
current. Owner decisions and not-a-product outcomes remain excluded from replay.

The owner re-extraction controls are deployed in the WordPress plugin. This does not
mean a live re-extraction has run; no messages were reprocessed from the owner screen.

## Production AI-only release checkpoint — 2026-11-01

After the owner workflow deployment, the user reported that the re-extraction panel said
AI extraction was not active. D-038 removes the `EXTRACTION_MODE` gate and regex fallback:
supplier message extraction always uses Gemini. Backend code commit `4033962` is live in
Railway deployment `f454e55f-bcbb-4d09-9cef-401176b8ed3a`, which reached `SUCCESS` from
`main` at release commit `25a5b1b`. The service now tracks the GitHub repository's `main`
branch; future pushes deploy automatically. `/health` returned `healthy` after rollout.

Railway lists `GOOGLE_API_KEY` as a configured service variable; its value was not read.
The WordPress panel at plugin commit `6448f09` checks whether the running backend supports
AI-only extraction and gives a specific missing-key notice when appropriate. No supplier
messages have been re-extracted yet.

## Historical live product setup — 2026-10-31 (superseded by November 2 public check)

The streamlined owner pipeline screens are deployed from commit
`62173404ece9e09a2348c19429e451559d73c688` (Railway deployment
`c1d067de-5c64-4583-af4b-32230b7dae0e`). All eight owner setup screens and their scoped
APIs returned HTTP 200 during the read-only audit. The active reference snapshot contains
3,925 WooCommerce Store API rows, including the corrected 1.2 L and 180 g examples.
Fifty-four item matches remain pending; no owner decision or `ReferencePrices` write was
submitted during this audit.

The current queue has 48 done, 125 failed publication, 1 needs-review, and 2 waiting-
publication jobs. One `batch_in_progress` hold is active, 47 AI confirmations are held,
and the batch with 514 attempts needs owner review. Two blocked publication jobs now wait
for the hold decision; the 125 older generic `RuntimeError` failures remain failed. The
freshness screen returned 100 offers (61 blocked, 37 needing review, 2 warning), and the
Sheet-conflict screen returned zero conflicts.

The customer handoff is not verified: Railway `/api/shop` returns an empty list while
WooCommerce exposes two non-purchasable product records. The owner hold was not cleared,
and no failed job was retried or product published. See
[`pipeline-readiness.md`](pipeline-readiness.md) for the complete current checkpoint and
remaining release gates.

## Persian slash notation correction — 2026-10-31

ASCII `/` in Persian product measurements is a decimal separator: `1/2ل` means
`1.2 لیتر`, and `1/5ل` means `1.5 لیتر`. The dedicated fraction slash `⁄` remains a
fraction. Commit `47c4ee7` updates the shared parser and Gemini prompt and is live in
Railway deployment `a2c53fc2-25f8-41f2-9204-b90ec196c196`; `/health` and
`/health/detailed` return HTTP 200. The focused parser/importer/Gemini checks pass 54
tests.

The full refresh then failed after 189 seconds with
`woocommerce_transport_read_timeout_page_1`. Independent reads of the supplier site,
WordPress JSON and Store API timed out, including the one-product request. No new
snapshot was staged. The last complete candidate
`2976f2004a643e5546dc242ec876607dad1fd03d2fb6ee7cd124881d30497380` remains inactive;
its `1/2ل` row says `0.5 لیتر` under the superseded parser and must not be activated.
There is no active snapshot and no `ReferencePrices` write. A corrected full candidate
is blocked on successful access to the supplier API.

## Previous successful full refresh before slash correction — 2026-10-31

The repeated generic `woocommerce_transport_failed` refresh was traced to the importer
failing its full WooCommerce fetch without identifying the page or retaining the
transport exception. Commit `a157ee5` adds a full low-load restart (50-item pages,
two concurrent requests) for transport, rate-limit and server failures, plus
page-specific safe errors. Railway deployment `b7901971-078f-44f7-8ce5-46586d807e91`
and the matching WordPress browser client are deployed. An owner-authorized production
retry staged candidate `2976f2004a643e5546dc242ec876607dad1fd03d2fb6ee7cd124881d30497380`
with 3,919 rows over 40 pages and verified coverage. The `1/2ل` sample was mistakenly
parsed as 0.5 L. The candidate remains inactive and must not be activated; no
`ReferencePrices` write was made. The full local Python suite passed 282 tests before the
slash correction; 54 focused tests pass after it.

## Initial Rezvan source correction — 2026-10-30 (superseded checkpoint)

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

## Product-title measurement extraction before slash correction — 2026-10-31

`catalog/measurements.py` is the shared, deterministic parser for explicit Persian and
English product measurements. It recognizes compact suffixes such as `180گ` (180 grams)
and initially treated `1/2ل` as `0.5 liters`; this ASCII-slash interpretation was wrong
and is superseded above. The parser converts Persian/Arabic digits and common unit aliases
and keeps weight separate from volume. Unlabeled numbers and retail/package markers such
as `(304ف)` and `6ع` are not measurements. The WooCommerce reference importer keeps the
source title verbatim and canonicalizes recognized size tokens only in its matching name.

The supplier Gemini prompt now requires the exact full source title in `name`, with
normalized measurement values in separate fields. The adapter validates those fields
against explicit title evidence. If Gemini shortened the name, the adapter restores the
source title only when exactly one message line has the same base product identity; if
multiple title lines could match, it leaves the measurement blank and marks the product
for review. An earlier check parsed the compact `1/2ل` sample as `0.5 لیتر`; that output is
superseded by the decimal-slash correction. The earlier full-suite result was 280 tests;
after the correction, 54 focused tests pass. The correction refresh attempt did not stage a
new snapshot or write `ReferencePrices` because the source API timed out.

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
