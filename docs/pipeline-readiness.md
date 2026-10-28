# Whole-pipeline readiness — 2026-10-28

**Result: local implementation is materially connected, but the complete release is
not ready for production.** This checkpoint reuses prior live-read and audit evidence;
it does not infer current live health from local tests. No production writes, imports,
orders, payments, plugin deployment or publication occurred in this iteration.

| Handoff | Evidence and readiness |
|---|---|
| Supplier → bot/server | A durable SQLite receipt/worker draft and synthetic forwarding replay exist. The Docker image now supervises the web process and durable worker against one mounted volume; production storage and worker configuration are being applied with this release. |
| Reference → extraction/review | Opt-in server intake applies the 24-hour owner choice, records snapshot/match provenance and holds uncertain writes through crash recovery. Local GAS category/history price-scale fallback and AI confirmation lifecycle are implemented. The daily PDF/Gemini scheduler stages candidates, and private Woo admin supports review, activation and verified `ReferencePrices` projection. Live Google calls/writes, owner-bridge configuration and connected staging remain unverified. |
| Offers → pricing/catalog | The server quote gates supplier offers at 7/14 days using source revision and original post time; explicit supplier-unavailable offers are excluded. Owned stock, fixed-pack procurement and expiry-aware lot reservation are connected locally. A private Woo screen creates audited clearance proposals from lot dates/cost plus owner-entered same-basket normal/current comparable market prices; it does not change customer prices. Owners can also record revision-bound count/damage/expiry adjustments and hold/release lots; reservations protect committed quantities. |
| Catalog → WordPress/basket | Local MolaviShop/Woo code receives freshness state; the Woo purchasability filter blocks at 14 days. The 250,000/5,000,000-toman delivery method passes synthetic zone checks. No connected staging publish or full checkout replay was performed. |
| Checkout → operator work | Woo owner screens cover stale-price verification, publication recovery and field conflicts from supplier updates. Order receipt handoff has retry behavior in the local draft. The supervised worker polls Products/Shop every 60 seconds by default and queues publication after Sheet-only edits; live Sheet operation/cadence, payment readiness and complete connected order work are unverified. |
| Payment → fulfillment/follow-up | A local, owner-audited paid-order workflow covers lot reservation, supplier receipt, substitutions/customer choices, refund receipts, packing, dispatch/tracking and aftercare. It has synthetic route and Woo runtime coverage, but is not enabled on Railway or tested with the connected gateway. No real/test order or payment was submitted to production. |
| Daily reconciliation/recovery | Cross-process server publication holds, owner-reviewed replay, a private Woo held/failed-work dashboard and audited lot corrections/holds are locally tested. Legacy/GAS writers can bypass publication locks; connected cutover remains incomplete. |

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

## Gaps that prevent an honest release-ready verdict

1. Complete persistent server storage and worker configuration, then cut over every
   legacy/GAS writer so the reference and publication guards cover all mutations.
2. Validate the polling worker and bidirectional Products-sheet reconciliation on
   the connected staging Sheet, including direct edits without a supplier post,
   invalid cells, row moves, freshness invalidation and WordPress publication cadence.
   Google Sheets has no cross-system transaction; the local path uses fresh rereads
   and records unresolved races for owner review.
3. Provision persistent storage for the inventory/workflow ledgers. Approve the
   clearance curve and proximity threshold; connect recalculation and owner alerts
   to live batch and market data before customer price integration.
4. Verify installed payment callbacks and complete checkout, substitution/refund,
   delivery, dispatch and aftercare in an isolated integrated staging environment.
5. Validate the implemented PDF/Gemini refresh, Sheets projection and owner review in
   connected staging, then replay the entire supplier-to-aftercare stream. Mockup v2
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
deployment, but the connected release is not ready; do not label local synthetic
evidence as production readiness. Preserve unresolved policy decisions in the backlog.
No roleplay scene is marked complete until the integrated stream is validated.

## Local implementation progress — 2026-10-28

The opt-in local implementation now includes field-level Sheets reconciliation,
owner conflict review, reference item correction, category/history price-scale fallback,
AI confirmation, scheduled PDF/Gemini candidate staging and the paid-order follow-up
described in `pipeline-handoffs.md`. 241 offline Python tests and 97 disposable
WordPress/WooCommerce assertions pass, including the owner operations dashboard,
clearance proposals and revision-bound stock adjustment/hold screens. These synthetic
checks do not make the proposal formula
approved or connect it to customer prices.
Current read-only Railway metadata shows a production-only project with no staging
environment or attached volume. The service is sleeping, `GOOGLE_API_KEY` is staged
but inactive, and `PIPELINE_DB_PATH`, `REFERENCE_STORE_PATH`, `REFERENCE_BRIDGE_TOKEN`
and `REFERENCE_OWNER_IDS` are absent. `GOOGLE_SERVICE_ACCOUNT_JSON` is present; the
user reported updating it, but its value was not read and its validity is unverified.
No configuration change or deployment was made. This updates local draft evidence only;
it does not change the incomplete whole-pipeline verdict.
