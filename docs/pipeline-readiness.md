# Whole-pipeline readiness — checkpoints through 2026-11-05

## Extraction no-result recovery — 2026-11-05

Railway commit `7e48c286184fab50ad61efac334b6b07d521dd66` is deployed successfully
as `166d02f4-29cd-4a4b-a13d-693ea4b9d6e5`. `/health` returned HTTP 200, and the
deployment logs show both `pipeline-worker` and `web` running. WordPress commit
`441de3853bbc8b2697e2d16d3e28163c268f320d` deployed and read back its owner workbench
JavaScript through cPanel.

Zero-product extraction results and model-generated `not_product` rows without owner
decisions now remain `no_product_candidate` and can be reviewed or re-extracted.
`owner_rejected` now represents a durable owner decision only. Legacy undecided
`not_product` messages remain recoverable, while explicit owner decisions remain
protected. Empty re-extraction runs stay in the pending inventory, and batch recovery
accepts the new terminal source statuses. Seventy-one focused Python tests, the
WordPress workbench interaction test, JavaScript syntax, Python compilation, and
`git diff --check` passed. No source, Sheet, product, WooCommerce, or publication data
changed.

At 15:49 UTC the owner queue still had no pending Telegram account identity. The five
messages received before identity capture was deployed were already acknowledged by
the old webhook and did not preserve the sender ID; one new message from the second
account is needed to create its approval card.

## Failed-batch recovery — 2026-11-05

Railway commit `c0f474efdb4f0dbbb1081bd7ebca21635dca6782` is live in deployment
`abc6c35f-1f1c-4eb0-ae8c-e543a9fd0b7e` (`SUCCESS`); `/health` returned HTTP 200 and
the worker and web processes entered `RUNNING`. WordPress commit
`899e1662eeb9d72a5c11f6c99c2aeb8c59b6f343` deployed and read back the updated owner
workbench JavaScript through cPanel.

Failed batches of every error class now appear in owner recovery. The owner can reconcile
all visible failures in one guarded catalog sync or select one receipt from its queue row.
The server closes only selected batches whose complete set of source messages is saved
with a terminal AI status and whose current catalog sync is fully verified. Incomplete
sources remain failed with their error receipt. Validation passed 73 focused Python tests
and the owner workbench Node interaction and syntax checks. No live catalog sync or data
mutation was performed.

## Historical price registration after reference assignment — 2026-11-04

The open `آبمیوه تکه میوه سانی نس،۲۴ عددی` case is a historical re-extraction. Its
reference assignment was already saved, but the final confirmation still requires an
explicit check that the supplier price is current. The old UI left the manual final
confirmation button enabled without that check, so it surfaced the historical-price
gate as a late error. The final button now stays disabled until the check is marked,
and the check persists when the reference-save action refreshes the card. A regression
test covers both behaviors. The selected reference's saved price role is `unit` while
the candidate comparison hints `package`; the card now shows this mismatch for the owner
to review without changing the saved assignment.

WordPress plugin commit `5865237739f3e2718134c284b06531ec5e010d17` was deployed and
read-back verified. The authenticated WordPress page, live JavaScript asset and AI queue
returned HTTP 200; the served JavaScript hash matches the deployed source. The exact case
remains open with its reference assignment saved. No final price decision was submitted.
The owner must verify the current supplier price before the UI enables final registration.

## Owner review responsiveness and reference assignment — 2026-11-04

Railway deployment `74741fbe-5551-4feb-87fa-74da0a0a9b79` runs backend commit `d9bea58`;
the WordPress plugin files are deployed and read-back verified from commit `308c189`.
The extraction API now
returns its 26 open cases in 2.91 seconds,
down from 22.69 seconds before reusing normalized reference-candidate data and accelerating
similarity scoring. The response includes 185 candidate suggestions in total, with up to 12
options per case. The active snapshot is attached to all 26 cases.

The reference selector appears near the top of each extraction card. It compares the
selected candidate's identity and price fields, records a reference and unit/package role,
and does not confirm current price or stock or publish the product. The existing current
source check remains required for historical price confirmation. Successful workbench
actions now refresh their affected queues instead of reloading every dashboard API.

Authenticated reads for all eight dashboard endpoints returned HTTP 200; the AI queue,
supplier price, and reference-item reads completed in 2.91, 1.21, and 1.39 seconds. The
WordPress page returned HTTP 200, its served JavaScript hash matches the deployed source,
and the AI and reference-item CORS preflights returned HTTP 200. No product decision or
reference assignment was submitted in this check. The focused local Python tests and the
JavaScript workbench interaction test passed.

## Live recovery checkpoint — 2026-11-02

Railway commit `3c0464ec1aae196d84b12636b82d74c494e394ba` is deployed successfully as
`ce182584-7a2c-4812-ac97-fef8e9517d8f`. WordPress plugin code from the same revision is
deployed and read back from cPanel. `/`, `/shop/`, the authenticated AI owner page,
Railway `/health`, and the owner operations, holds, Sheet-conflicts, AI review, and
reconciliation APIs return HTTP 200. The public Woo Store API returns one purchasable,
in-stock product; its detail route also returns HTTP 200.

The owner AI page now returns supplier post links and source messages, and mirrors its
case context and decision history into the WordPress review database. The live WordPress
AJAX receipt confirmed 123 case-context records stored (26 cases include source URLs).
The pending saved-message inventory contained one item; it was re-extracted successfully
and staged for owner review, with no automatic publication. Afterward the inventory was
21 available, 0 pending, 64 already staged, 668 dismissed by the owner, 5 preserved as
not-product, and 266 without text. There are 123 open AI review cases and no recent owner
decisions. The new staged offer still lacks a confirmed unit/package price basis and
current stock verification, so it remains held.

The two previously visible Sheet conflicts were resolved with the Railway/server value,
following the owner's conflict rule. There are now zero open conflicts and zero
publication holds. The durable queue still shows 180 failed jobs: 178 publish jobs with
generic `RuntimeError` state and 2 batch jobs with `JobConflict`; it also shows 703
cancelled and 517 done. These historical failures were not replayed because their stored
records do not preserve the underlying exception detail. The 109-receipt reconciliation
shows 189 offers, 143 offers needing review, 78 receipt-linked products, 36 completed
publication receipts, and 68 failed publication receipts. The current Woo check reports
no missing or blocked product among those receipt-linked products.

Infrastructure and the public site are healthy, while the owner-review backlog and old
failed jobs remain unresolved. AI extraction accuracy has not been measured against an
owner-labelled corpus; no open AI cases were approved or rejected during this checkpoint.

## Current audit review — 2026-11-02

The November 2 audit supersedes the October 31 catalog counts as a description of the
current public storefront. A read-only check found one WooCommerce Store API product,
marked purchasable and in stock, and one product from Railway `/api/shop`. This verifies
current public visibility for that product; it does not trace a supplier receipt through
approval and publication.

The pre-release Railway `/api/shop` response exposed internal price-band and margin-policy
fields. Commit `53531d00632a070b8fe505e890a28d681fed945c` is now deployed in Railway as
`2265d265-34ed-4762-a805-dcdc3eec06b5` (`SUCCESS`). The public `/api/shop` read-back shows
customer-facing prices, stock state, and discount progress, with no supplier channel,
price floors, ceilings, or margin settings. The WordPress uploader also read back all six
changed plugin files. No live admin click-through has been performed.

The November 2 audit identified bulk decision previews/history, labelled AI accuracy
measurement, and receipt-to-publication reconciliation as priorities. The reconciliation
deployed implementation adds a read-only owner report joining durable receipts, MessageData,
Offers, Products, publication jobs, and WooCommerce visibility. Historical receipts do
not have durable receive timestamps, so latency measures apply only to new receipts after
the schema update. AI field accuracy remains unmeasured until an owner-labelled corpus is
provided and evaluated.

The owner report returns HTTP 401 without its owner credential, confirming the anonymous
route is closed; an authenticated report view was not exercised. Private queue counts and
review decisions were not rechecked during this public audit.
The October 31 counts below are historical and must not be treated as November 2 queue
measurements. No production products, review decisions, orders, or payment records were
changed during the audit.

## Historical live product setup audit — 2026-10-31

**Current state — 2026-10-31:** The public storefront remains live, and the owner product
pipeline menu was streamlined in commit `62173404ece9e09a2348c19429e451559d73c688`.
Supplier extraction and price-role fixes are in commits `4c5d83852e4eb6ec2a627f433ed9286d1a8aa637`,
`1d6c2de1179aeddaf1e3297c854dee7e040c204e` and `49b93820b8601f49e793849edfe1bf19b997c95f`.
The latest commit deployed successfully as `d8f294a2-7d38-43b9-b8ae-990a4dde0bc1`;
`/health` and `/health/detailed` returned HTTP 200.
The WordPress owner review UI was deployed and read back from commit
`4c5d83852e4eb6ec2a627f433ed9286d1a8aa637`.
The latest read-only product setup audit is below. Payment setup remains out of scope.

## Latest live product setup audit — 2026-10-31

- All eight owner product setup screens and their scoped APIs returned HTTP 200 in the
  authenticated audit. No review decision, snapshot activation, Sheet write, product
  publication, order, or payment action was submitted during this audit.
- The active reference snapshot contains 3,925 rows from the WooCommerce Store API and
  reports fresh. Live rows confirm `آبلیمو دست چین1/2ل (304ف)6ع***` as 1.2 liters and
  `آبلیمو مجید کوچک180گ(138ف)20ع***` as 180 grams with 20 units. Fifty-four product
  matching cases remain pending; the freshness-decision endpoint returned zero events.
- Supplier-price freshness returned 100 offers: 61 blocked, 37 needing review, and 2
  warning. The conflicts screen returned zero open Sheet conflicts.
- The operations dashboard returned all 128 actionable rows without truncation. Queue
  states were 48 done, 125 failed, 1 needs review, and 2 waiting for publication. One
  `batch_in_progress` hold remains; 47 AI confirmations are held behind it. One batch
  with 514 attempts needs owner review. The 125 old failed publication jobs still have
  generic `RuntimeError` failures; the two newer blocked jobs now wait explicitly for
  publication-hold review. These jobs were not retried.
- **Catalog handoff is still blocked:** Railway `/api/shop` returned zero products, while
  WooCommerce's public Store API returned two records and marked both non-purchasable.
  The public products therefore do not prove a current Sheet-to-Woo handoff. The precise
  cause of the empty Railway catalog (source rows versus freshness eligibility) remains
  unisolated. Do not publish or clear the hold as part of a read-only audit.
- Railway reports both production services online, one replica for each, and no active
  warnings or recent deployment failures. The updated WordPress plugin files were
  uploaded from the same commit and read back successfully.
- Eight local measurement and supplier-freshness core tests passed in this workspace.
  The route-suite rerun was blocked because this executor's Python environment lacks
  Flask; the 293-test post-commit run recorded earlier remains the full-suite evidence.

## AI extraction review triage — 2026-10-31

- A read-only check of the live owner queue found 47 pending AI confirmation cases. Five
  measurement-mismatch cases had a unique source title but the flagged measurement type
  was absent from that title. The extractor now clears that unsupported model field
  without creating a review reason; genuinely different or ambiguous titles still hold.
- Six QA flags labeled a normal zero or very small positive discount as unusual because
  the old check treated every discount below 5% as suspicious. The check now keeps the
  above-consumer-price and over-80%-discount safeguards while allowing zero and small
  positive discounts.
- The deeper source audit found that some apparent role ambiguities were parser misses:
  price labels followed the product title and carton-count lines, sometimes after blank
  lines, so the old context collector stopped too early. It also missed labels such as
  `قیمت هر یک بسته` and `قیمت مصرف`, and sometimes retained an AI-copied supplier amount
  as a consumer price without a consumer label. The parser now matches each amount to its
  own explicit unit, package or consumer label in that product's listing context; a
  same-valued consumer label on another product cannot validate it. Persian number words
  such as `یک گرم` and `یک لیتر` are now recovered from the exact source title. Slash-grouped
  prices such as `۱۲۰/۰۰۰` are read as 120,000.
- The user's rule is that one unlabeled amount in these wholesale channels is the supplier
  sale amount. Whether it is per unit or per package remains debatable without a source
  label or reliable history/reference evidence, so that basis stays in review. Some of
  the existing 37 supplier-role and 10 pack-count/history cases had explicit labels that
  the old parser failed to capture. A read-only reanalysis found unique source-title
  matches in 34 saved cases and exact explicit unit/package amount matches in 11 cases
  (10 unit-label and 4 package-label matches; these overlap). Those matches may remove
  the price-role reason on reprocessing, but other review reasons can still apply. Cases
  with no evidence for the price basis remain held.
- The extraction prompt now bases confidence on identity, amount, currency, price role
  and any count needed for unit-price calculation; missing optional details do not lower
  confidence. Existing queue rows were not reprocessed or otherwise changed. The
  post-deployment read-only queue GET returned HTTP 200 with the same 47 pending cases and
  publication hold active; the stored old reason counts therefore remain until a safe
  reprocessing or owner review. No owner decision or product publication was submitted.
- The focused extraction, price-scale and measurement tests pass: 43 tests, including
  product-local labels, a repeated amount labeled as consumer price on another product,
  source-title size/count correction, spelled-out measurements, and normal small discounts.

The previous 2026-10-31 refresh-timeout note below is a historical checkpoint. The
current active snapshot supersedes its statement that no snapshot was active. The older
3,919-row snapshot still contains the incorrect 0.5 L parse and must remain inactive.

**Initial reference-source correction — 2026-10-30:** Rezvan reference products are required
from its WooCommerce Store API, not the supplier PDF. Commit `3d8a7d2` is deployed to
Railway as deployment `f87efd11-381c-4195-b82e-ce26263b78be`, the owner review files are
deployed and read back, and fresh candidate `4bf9d367c07dcf1af29e4fc61dfb1eddcb23cbbc4db3ffcdeb63fc987e80f7a9`
contains 3,895 products from 39 API pages. It is inactive pending owner review. The two
older 10-row PDF snapshots are historical, incomplete and inactive.
The latest `Wholesale-project` deployment is healthy, with one running replica, the
500 MB `/data` volume, no staged Railway changes and no active service warnings. The
production `/health`, `/health/detailed` and `/api/shop` routes return HTTP 200. The
service-account reader returns the two current Products rows from Sheets. No customer
order, charge, refund or supplier purchase was made.

## Measurement extraction check before refresh — 2026-10-31

The earlier title parser separated explicit weight and volume, accepted compact Persian
unit suffixes, and preserved the exact source title. It incorrectly treated ASCII `/` as
a fraction, so its old `1/2ل` check produced 0.5 L. The corrected parser now interprets
ASCII slash as a decimal separator, while dedicated `⁄` remains a fraction. Its focused
tests pass; the supplier API timeout prevented creation of a corrected production
snapshot. The old candidate remains inactive and `ReferencePrices` was not written.

## Current live recheck — 2026-10-29

- `/` and `/shop/` return HTTP 200 and render the production storefront with two live
  products. The public Coming Soon screen is absent. The root previously displayed the
  default posts archive; WordPress now uses Shop page 7 as its static front page.
- The public Woo Store API returns two in-stock IRT products. The Shop page's Woo page
  assignments remain Cart 8, Checkout 9 and My Account 10.
- Woo's `molavi_tehran_delivery` method is enabled with the recorded 250,000 toman fee
  and 5,000,000 toman free-delivery threshold. Its Tehran zone has no locations, so the
  current zone configuration does not match customer addresses and cannot provide a
  shipping rate. Define the actual coverage before changing the zone.
- The 2026-10-29 environment Administrator was denied by a private owner allowlist
  recorded as pointing at a third account. A fresh live Users-page check on 2026-10-30
  found two Administrator accounts and no third account. At the user's direction, the
  existing second account was made the sole owner in protected WordPress and Railway
  configuration. At this 2026-10-29 checkpoint, the live owner screen's data connection
  still needed a check from that account.
- No order was placed and no payment, shipping-zone, gateway, or supplier-data setting
  was changed in this recheck.

## Telegram post check — 2026-10-29 22:18 UTC

- The primary `Wholesale-project` Telegram webhook received 27 `POST /webhook`
  requests around 22:14 UTC. All 27 returned HTTP 200 and the runtime log recorded
  each message as queued.
- A read-only check of the matching rows in Sheets found none of those message IDs
  in `MessageData`; there were also no matching `Offers` rows or product projection
  for those messages. No WooCommerce product update is confirmed for this batch.
- The durable handoff checks for an approved, usable reference snapshot before it
  calls the Sheets finalizer. The latest visible runtime record is a candidate
  staged for owner review. This is consistent with the batch waiting at the
  `waiting_reference` gate; the owner-only queue dashboard was not accessible to
  confirm the SQLite job state from this session.
- The webhook committed inbound receipts to the persistent pipeline queue before
  returning success. The posts remain recoverable there; they have not completed
  the Sheet extraction or Woo publication stages. Review the staged reference
  candidate through the existing owner screen, then recheck the same batch before
  treating these posts as processed.
- The message contents and chat identity are intentionally not copied into this
  project record.

## Owner access correction — 2026-10-30

- The authenticated live Users page contained two Administrator accounts; the account
  named by the previous owner configuration did not exist.
- At the user's explicit direction, the existing second Administrator was set as the
  sole Molavi owner. Protected WordPress configuration and Railway's `REFERENCE_OWNER_IDS`
  were updated and read back. The Railway deployment completed successfully, and the
  public homepage still returned HTTP 200. The missing reference bridge and backend route
  were repaired later the same day; see the next section.
- This access change does not approve the staged reference snapshot or process the 27
  queued Telegram posts. The owner must still review the reference candidate. Account
  identifiers and private configuration values are kept out of Git.

## Reference bridge repair — 2026-10-30

- The owner page's “reference list refresh connection is not configured” message was
  caused by missing URL/token constants in protected WordPress configuration. The optional
  plugin `includes/runtime-config.php` was absent. A fresh dedicated bridge token was set
  on Railway and matched in protected `wp-config.php`; user 2 remains the sole owner.
- The Railway service had been running `main` at commit `2e32dea`, which returned 404 for
  `/reference/snapshots`. It now tracks the pushed release branch
  `molavisale-release-2026-10-28`; deployment
  `6062fc3d37a581f9666a24612288df63fd1da084` completed successfully with the existing
  `/data` volume attached.
- A read-only authenticated request using actor `wp:2` returned HTTP 200 and two snapshots;
  the active snapshot remains unset and refresh state remains `staged`. The public homepage
  also returned HTTP 200. No refresh, review, activation, or `ReferencePrices` write was
  performed. The owner page still needed a WordPress-host transport fix at this checkpoint.

## Owner browser transport fix — 2026-10-30

- WordPress PHP timed out (`cURL error 28`) to both the snapshots endpoint and Railway's
  `/health`, while a public HTTPS control succeeded. Railway was healthy and had no
  incoming request from the WordPress host. Waking the Railway service did not change
  the result.
- The owner snapshot page now fetches and submits from the signed-in browser. WordPress
  signs a ten-minute HMAC capability with the private bridge secret; that secret remains
  in protected configuration. The backend accepts the capability only for
  `/reference/snapshots`, checks the actor against its owner allowlist, and applies CORS
  only for the exact `https://www.molavisale.ir` and `https://molavisale.ir` origins.
- Railway's source branch drifted to `main`, so the first Git push did not deploy the
  fix. It is now configured to follow `molavisale-release-2026-10-28`; final deployment
  `17f7f764-9199-4b81-8222-5e7a89cee594` succeeded at commit `cf252f1` with the owner
  allowlist set to `wp:2`. The tracked WordPress browser client and signer were uploaded
  and read back from the live plugin.
- The live owner page emitted its short-lived token. GET with that token and the exact
  site origin returned HTTP 200 and two snapshots; CORS preflight returned HTTP 204.
  A post-rollback read-only API check accepted `wp:2` and denied `wp:1`. The candidate
  remains staged, no snapshot is active, and no refresh, review, activation or
  `ReferencePrices` write was made. User 2 should visually check the page before taking
  an owner action.

## Latest owner access and snapshot UI — 2026-10-30

- At the user's direction, both existing WordPress users 1 and 2 are now approved in the
  protected WordPress owner list and Railway's matching `REFERENCE_OWNER_IDS`. The
  WordPress file was privately backed up, only the owner list was changed, and read-back
  verified `[1, 2]`.
- Commit `e071d3b0263f38c97464316fc697d0b449a53ba7` simplified the Persian review screen
  with status labels, a price table, sample counts, and clear confirmations before PDF
  retry or writing prices. Deployment `1ede0c85-287d-4ec7-a811-9566a4ed4822` succeeded
  from `molavisale-release-2026-10-28`; the deployed JavaScript matches the committed file.
- Signed in as WordPress user 1, the owner page and script returned HTTP 200. Read-only
  snapshots requests for `wp:1` and `wp:2` each returned HTTP 200; CORS preflight returned
  HTTP 204. The API still lists two candidates, no active snapshot, and `staged` refresh
  state. The public homepage and Railway `/health` returned HTTP 200.
- A synthetic DOM render check passed. The connected browser extension was unavailable
  for a screenshot-based visual check. No refresh, review, activation, or
  `ReferencePrices` write was submitted.

## Rezvan source correction — 2026-10-30

- A read-only audit of the public Woo Store API returned 3,895 products over 39 pages;
  all products have a title, SKU, category and positive IRR price. The new importer
  retains each full product title, SKU and category, converts IRR to toman, and derives
  package/set/volume/weight hints only from explicit title markers and API attributes.
- The refresh worker now uses the Woo API. Commit `3d8a7d2` / deployment
  `f87efd11-381c-4195-b82e-ce26263b78be` is healthy, and the owner page identifies the
  source and prevents review or activation of old PDF/Gemini snapshots. Candidate
  `4bf9d367c07dcf1af29e4fc61dfb1eddcb23cbbc4db3ffcdeb63fc987e80f7a9` is staged with
  3,895 rows, 39/39 pages and verified coverage. It is inactive; `ReferencePrices` has
  not been written.
- The previous PDF failure (`extraction_batch_row_count_mismatch_page_15_left`) did not
  create a snapshot. The two existing 10-row PDF/Gemini snapshots remain preserved for
  history, with coverage unverified and inactive.

## Customer-page and Git check — 2026-10-29 22:36 UTC

- The site-wide Persian header, footer and WooCommerce styling were deployed from
  pushed source commit `a519024f889dbce4a2c8de1f040343068c6fc59b`.
- A live browser audit loaded `/`, the first product page, `/cart/`, `/my-account/`
  and `/checkout/`. All five displayed the shared Persian header and footer, the
  old default WordPress navigation was absent, and no page errors or unexpected
  redirects were reported. The checkout audit used an empty cart and did not submit
  anything.
- The repository is clean and its `HEAD` matches the configured upstream. The local
  post-commit hook is enabled to push each new commit; the deploy script requires
  the source to be committed and pushed before uploading tracked plugin code.
- Git tracks the site code and safe documentation, not WordPress database content.
  WordPress pages/products/orders and private runtime records still need host/database
  backup or a safe export, as described in `site-change-workflow.md`.

## Fresh WordPress and live catalog

WordPress/WooCommerce runs on the user's fresh `h434558_Salesman` database. The
document root backup remains at `/home/h434558/public_html_recovery_20261029`; legacy
catalog, orders and media were not restored. The Shop page is WooCommerce page 7 and
now uses `[molavi_storefront]`, the production WooCommerce storefront based on the v2
visual and pricing direction. It is also the current static front page. WordPress
renders two live product cards. Responsive visual and business review remains pending.

Railway's Woo publish credential was rotated and matched on the private WordPress side.
The authenticated `/shop/publish` call succeeded, updating the two existing products.
Woo's public Store API now reports customer prices in toman (`IRT`): 73,320 and 71,850,
with regular market prices of 85,000 and 75,000. Supplier costs remain in private
product metadata; they no longer appear as the public Woo sale prices.

A previous disposable guest-cart check confirmed dynamic prices and the 250,000 / 5,000,000
toman fee calculation in isolation; those carts were cleared and no order was submitted.
The current live zone has no location rules, so that isolated result does not establish
that customers can receive a delivery rate on the public checkout.

## Whole-stream handoffs

| Handoff | Current evidence and status |
|---|---|
| Supplier → bot/server | Railway and its worker are online. Durable queue records are present, but one batch needs owner review and 125 publication jobs remain failed. The Railway shop API currently returns zero products. |
| Reference → extraction/review | The active snapshot has 3,925 WooCommerce Store API rows and fresh status. Corrected `1/2ل` and compact gram extraction are present. Fifty-four matching cases remain pending; the owner has not recorded a decision. |
| Offers → pricing/catalog | Freshness gating is active. The latest response contains 100 offers (61 blocked, 37 needing review, 2 warning). The current publication hold keeps AI and publication actions from completing until owner review. |
| Catalog → WordPress/basket | The public Woo Store API returns two records, both non-purchasable, while the Railway shop API returns none. The catalog handoff is not verified. The delivery calculation exists, but the live Tehran zone has no matching locations. |
| Checkout → operator work | Woo page assignments are present and both existing owner accounts can access the owner reference page. The active snapshot is available; matching and hold decisions remain pending. The live Tehran shipping zone still has no location rules. |
| Payment → fulfillment/follow-up | Paid-order lifecycle is synthetic-tested. No live payment callback or order was run; inventory/workflow ledger remains on Railway SQLite and is not mirrored to Sheets. |
| Daily reconciliation/recovery | The server worker and owner review surfaces exist. Bilateral Sheet edits and cutover of legacy/GAS writers remain unverified; legacy writers can bypass server locks. |

## Remaining implementation and review — excluding payment

1. Set the exact coverage locations for the already configured Tehran shipping zone.
   The fee and free-delivery threshold are recorded; the actual eligible addresses are
   not. The live zone currently has no location rules.
2. Resolve the `batch_in_progress` publication hold through owner review. This also
   releases the two publication jobs waiting on that hold; the 125 older failed jobs
   still need separate diagnosis and replay planning.
3. Trace why the Railway `/api/shop` response is empty while Woo still has two
   non-purchasable records. Verify the Sheet projection and freshness eligibility before
   publishing anything.
4. Review the 54 pending reference matches and supplier-price cases in the owner screens.
   Do not activate or write prices without the owner's decision.
5. Verify bilateral Products-sheet edits, conflict receipts and Woo publication in
   connected staging, then reconcile and cut over legacy/GAS writers after a guarded
   replay. This Railway project has no isolated staging environment; legacy writers can
   bypass server locks.
6. Finish the clearance formula, near-cost threshold, alert channel and approval rules;
   these require business settings and cannot be inferred from the code.
7. Validate persistent inventory/order-ledger reporting, backups and restore, operator
   recovery controls, browser/Elementor/Wordfence interactions, and the household then
   retailer end-to-end workflows. The v2 mockup's guest-request form is still only a
   demonstration; production uses WooCommerce checkout.

Payment configuration and checkout-payment validation are explicitly excluded from this
work and remain separate follow-up scope.

The local Products-sheet path covers normalized non-provenance fields, preserves
source evidence, blocks conflicted products and polls Sheet-only changes every 60
seconds. Synthetic coverage is not evidence of connected reconciliation. The earlier
source audits remain in `gas-migration-audit.md` and `recovered-gas-audit.md`; no broad
historical audit was repeated. Mockup v2 remains unapproved and no roleplay scene is
marked complete.

## Verification record

The latest disposable WordPress 7.1.3/WooCommerce 11.2.1 runtime run passed 104
assertions, including the new regression checks that customer-facing Woo prices use
the calculated band while supplier cost remains private and continues driving the
cart formula. The previous 246 offline Python tests remain the applicable server
baseline. The latest Railway deployment `db46d33c-adca-4bad-8a5d-2c2207e1947d` is
successful, and its logs confirm both `web` and `pipeline-worker` entered RUNNING state.
The separate `FollowUP-bot` service was not changed.
