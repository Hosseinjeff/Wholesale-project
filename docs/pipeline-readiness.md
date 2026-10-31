# Whole-pipeline readiness — 2026-10-31

**Current state — 2026-10-31:** The public storefront remains live, and the owner product
pipeline menu was streamlined in commit `62173404ece9e09a2348c19429e451559d73c688`.
Railway deployment `c1d067de-5c64-4583-af4b-32230b7dae0e` is successful and online.
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
