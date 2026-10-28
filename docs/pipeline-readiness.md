# Whole-pipeline readiness — 2026-10-28

**Result: the server worker, persistent storage and owner dashboard bridge are deployed,
but the live reference sample is blocked by an invalid Gemini API key.** Production
commit `813d9c4` is healthy at `wholesale-project-production.up.railway.app`; the
500 MB `/data` volume is mounted and both supervised processes start. The worker fetched
the real Rezvan PDF, but Google returned `API_KEY_INVALID`. No reference candidate was
staged or activated, and no `ReferencePrices` write, customer order or payment occurred.
The Woo owner dashboard has a nonce-protected retry after failure. Railway's free-plan
sleep setting can pause scheduled work while the service is idle.

| Handoff | Evidence and readiness |
|---|---|
| Supplier → bot/server | Production has a persistent SQLite volume and supervised web/worker processes. The 60-second Products/Shop polling worker starts; a verified bilateral edit/reconciliation receipt is still outstanding. |
| Reference → extraction/review | The production worker reached Rezvan's PDF and Gemini. Gemini rejected the configured key (`gemini_key_invalid`), so no candidate or sheet write exists yet. Existing MolaviShop Woo admin has an owner-only manual retry, candidate review, activation and verified `ReferencePrices` projection. The bridge is configured for WordPress user 3. |
| Offers → pricing/catalog | Production server and MolaviShop files are deployed. The 7/14-day freshness gates, fixed-pack purchasing, lot reservations, audited inventory changes and clearance proposals have local tests; live catalog reconciliation and the clearance alert/price formula still need connected validation. |
| Catalog → WordPress/basket | Updated files are installed in the existing MolaviShop plugin. Woo delivery uses 250,000 toman below the 5,000,000-toman goods threshold in local checks. Admin/browser compatibility and full checkout have not been replayed on the live site. |
| Checkout → operator work | Owner dashboards cover stale-price review, reference refresh retry, publication recovery, Sheets conflicts and order follow-up. No production checkout or payment was run, and the installed payment callback remains unverified. |
| Payment → fulfillment/follow-up | The complete paid-order workflow is implemented and synthetic-tested, but its inventory ledger is still SQLite-only and no real/test order or payment was submitted. |
| Daily reconciliation/recovery | Persistent worker and owner review surfaces are live. Legacy/GAS writers can bypass the server locks, so migration cutover and whole-stream recovery remain open. |

## Previous read-only observations — 2026-10-25

Server health and detailed health: HTTP 200, version 2026-10-17-shop-publish.
Server reports service_account sheet writer. Catalog endpoint returns 32 products.
Public WooCommerce Store API responds 200. Telegram webhook reports no pending
updates or last delivery error. These show the existing system is running.

Detailed health calls its GAS connection unreachable. GAS is not the intended future
runtime under D-007; that result alone must not block independent server work or
be misrepresented as the complete system being down. Direct executor Sheets metadata
read failed parsing configured service-account JSON. This is an executor check failure,
not proof of failure of the deployed server's sheet credentials.

## Remaining work before a release-ready verdict

1. Replace the invalid `GOOGLE_API_KEY` in Railway, then use WooCommerce → Reference
   Prices → retry to stage one Gemini candidate. Review it in WordPress before any
   activation and verify the resulting `ReferencePrices` projection.
2. Validate the polling worker and bidirectional Products-sheet reconciliation on
   the connected staging Sheet, including direct edits without a supplier post,
   invalid cells, row moves, freshness invalidation and WordPress publication cadence.
   Google Sheets has no cross-system transaction; the local path uses fresh rereads
   and records unresolved races for owner review.
3. Mirror inventory/workflow ledgers to Sheets or another governed owner-visible
   store. Approve the clearance curve and proximity threshold; connect recalculation
   and owner alerts to live batch and market data before customer price integration.
4. Verify installed payment callbacks and complete checkout, substitution/refund,
   delivery, dispatch and aftercare in an isolated integrated staging environment.
5. Cut over legacy/GAS writers after staging replay. The project has no isolated Railway
   staging environment; the deployed service is on a sleep-enabled free plan. Mockup v2
   remains unapproved.

The local Products-sheet path covers all normalized non-provenance product fields,
preserves source evidence, surfaces owner choices in WordPress, blocks conflicted
products from quotes/publication and polls for Sheet-only changes every 60 seconds by
default. Its synthetic tests do not prove live Sheet access or end-to-end staging readiness.
Use gas-migration-audit.md and recovered-gas-audit.md for existing defect evidence;
no broad historical audit was rerun here.

## Working direction

User clarified: prioritize the whole project, coordinate with existing WordPress
plugins and continue implementation. The user explicitly authorized whole-plan
deployment and the implementation is deployed to the existing production service. Do
not label the incomplete live sample or local synthetic evidence as full-pipeline
readiness. Preserve unresolved policy decisions in the backlog.
No roleplay scene is marked complete until the integrated stream is validated.

## Current deployment checkpoint — 2026-10-28

The implementation includes field-level Sheets reconciliation,
owner conflict review, reference item correction, category/history price-scale fallback,
AI confirmation, scheduled PDF/Gemini candidate staging and the paid-order follow-up
described in `pipeline-handoffs.md`. 245 offline Python tests and 100 disposable
WordPress/WooCommerce assertions pass, including the owner operations dashboard,
clearance proposals and revision-bound stock adjustment/hold screens. These synthetic
checks do not make the proposal formula
approved or connect it to customer prices.
Railway's deployment is live with its volume and worker variables; `GOOGLE_API_KEY` is
present but rejected by Google's API. The user-reported service-account value remains
redacted. WordPress bridge config is stored outside Git under the existing plugin and
the production retry screen is installed. No staging environment exists. No reference
activation, customer order or payment occurred; the whole-pipeline verdict remains
incomplete.
