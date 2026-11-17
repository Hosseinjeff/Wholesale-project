# Staging release: code reference matching and text-only intake

Verified 2026-11-17, Asia/Tehran. Scope is Staging under the standing deployment
instruction. Production was not changed.

## Current follow-up: owner suggestions and bounded dashboard reads

- Current pinned backend: `cfd6515298cb2832daf7e2b9d7f3124ff93c245a` on
  `codex/free-google-staging-release`.
- Railway deployment: `c78e7cf4-c4ce-4677-abf6-5597f4c237dd`, terminal `SUCCESS`.
  The preceding integration deployment `95e56da4-0774-42e5-a7d8-86d5342a5115`
  also succeeded at `6ce42b3`.
- WordPress Staging asset uploaded and read back through the tracked deployer.
  Current asset SHA-256:
  `7a8694fffadf59f2bf2ac094c6a10e4fdba7554487ad50ab250fef29b2c75d8d`.
- Backend health returned 200 with version
  `2026-11-17-owner-reference-suggestions` after the integration deployment.

The owner can explicitly request a reference suggestion from AI-confirmation or
reference-item review. One idempotent task goes into the existing WordPress-owned
durable queue. Code handles exact/approved identity first; qualified free models and
optional cached embedding retrieval handle unresolved cases when configured. GETs
attach stored receipts only. A suggestion cannot approve a link, choose a price role,
edit product prices, or publish. Workers discard changed source/reference contexts;
quota waits retain their exact due time without consuming failure attempts.

The earlier proxy issue reproduced under parallel dashboard reads. WordPress's
45-second upstream limit cancelled Railway reads; serial browser requests removed
most failures. Supplier-price evidence still timed out because it queried state
separately per product. Bounded source/evidence transactions reduced its observed
read to 8.1 seconds. AI and price schema setup now uses the existing provider's
schema cache. Read-only 502/503/504 responses receive one serial retry after one
second; subsequent failures remain visible. Owner GETs now have a bounded 90-second
timeout for state round trips and executor
startup; POSTs retain 45 seconds. History is requested only for reviewable offers
and visible cases. No infrastructure capacity increase, idle worker, browser
polling, or write retry was added.

Validation: 276 targeted Python checks and the synthetic Chromium desktop/mobile
workflow suite passed. The final read-lookup/schema changes and combined native
connector source passed 103 affected checks. Chromium also proves one transient
read retry and one suggestion request without an approval write. Final
authenticated Staging acceptance is recorded below.

Free-model routing remains disabled. Actual project RPM/TPM/RPD rows and reviewed
model/task benchmarks are still required before activation. Semantic search also
needs an explicit bounded reference embedding index. No Google inference request or
real owner approval was submitted for these release checks.

### Final authenticated acceptance

Against `cfd6515` and the deployed proxy, all eight unique owner dashboard GETs
returned HTTP 200: current product status, AI confirmations, reference-item review,
supplier-price evidence, Sheet conflicts, publication holds, reference decisions and
operations. There were no JavaScript errors and no document overflow at 1365px or
390px. The browser guard allowed sign-in and same-origin proxy GETs, blocked
workflow writes and other origins, and retained only sanitized status/timing data.

Observed API timings were 14.8s, 40.4s, 21.8s, 4.9s, 3.1s, 7.8s, 0.6s and 0.6s,
respectively. No gateway retry was needed on this final pass. Correctness and the
reported gateway failure are verified for this pass; overall initial read latency
still needs improvement, especially AI/reference review. Do not claim fast page
loading or that model precision has been benchmarked.

The release incorporates the concurrent native WordPress work through `9234851`.
Only the changed storefront assets/proxy were deployed by this follow-up; native
route activation and the production state cutover retain their separate receipts.

## Original text-only release

- Backend commit: `466c71d80f9eef0117284ee600626b7fe035cc16` on
  `codex/free-google-staging-release`, pinned in Railway.
- Railway deployment: `622f5bb8-e8ea-425e-b8ef-1b43649a1b26`, terminal `SUCCESS`.
- Project `b693c2b6-6d01-4930-87ab-8a722542b4c0`, Staging environment
  `da7513fc-47f3-42f9-91dc-c3827895b49f`, service
  `ca660193-e903-4617-a247-b6057ceacd9c`.
- WordPress `includes/owner-workflows.js` uploaded to `staging.molavisale.ir`
  through the tracked deployer. The UAPI read-back and served asset match the
  release bytes: SHA-256 `6a2aa41bc09b9b038ccaf38c95636c112cc11fe19a6b9e47533e085677bb82fe`.

The release is based on the current Staging line at `6e6da4c`, preserving its newer
storefront/dashboard, incremental reference refresh, WordPress state provider and
memory-reclamation work. It does not replace that line with the older main-branch UI.
The pending binary state transport correction is included. Quota tables/cache use
the authoritative WordPress pipeline store in WordPress-state mode; compressed
cache values travel as scalar text rather than binary SQL parameters.

## Verified behavior

- `/health` returned HTTP 200, `healthy`, version
  `2026-11-17-free-routing-text-only`.
- Native Staging owner sign-in and the owner page returned HTTP 200. The newly
  provided staging credentials are available; the old missing-credentials blocker
  is no longer current. Do not print credentials, cookies, nonces or bridge values.
- A direct authenticated backend preview returned HTTP 200, `ok: true`,
  `image_extraction_enabled: false`, `media_review_total: 0`.
- A synthetic request to the retired image extraction action returned HTTP 410,
  `image_extraction_disabled`. No source ID from the business was submitted.
- Photo-only messages are attachments. Text/captions remain extractable; images,
  original source records and historical audit/transcript columns are preserved.
- Exact compatible identities and trusted approved mappings avoid embedding/model
  calls. Numeric contradictions and final explicit-attribute checks run in code.
- 213 focused extraction/reference/queue/state/memory tests and 40 publication,
  operations and photo-publishing tests passed. The synthetic Chromium dashboard
  suite passed on desktop/mobile, with no browser errors. Those test writes were
  intercepted synthetic requests, not live business decisions.

Railway reports no pending patch, warnings, crashes or recent failures for the new
deployment. Sleep mode remains enabled. Do not infer sleeping from configuration
alone or keep waking the service with repeated health probes.

## Outstanding work

1. **Free model routing activation:** configure the actual project/model RPM, TPM,
   RPD, shared buckets and Staging/production allocations. These values cannot be
   recovered from the connected tools. The owner has been asked for AI Studio rows;
   no allowance, paid eligibility or qualification was guessed.
2. **Matching benchmarks and embedding index:** evaluate reviewed Persian products
   for accuracy and latency, qualify each model for its tasks, then build an explicit
   bounded reference embedding index. The owner suggestion workflow is integrated;
   Google comparison/retrieval remains opt-in until these prerequisites are met.
3. **Business acceptance:** exercise text/caption intake, explicit reference
   assignment, owner price review and guarded publication with approved Staging
   test data. No real approve/dismiss/reprocess/reference-activation action was
   submitted by this release verification.
4. **Further native execution and read latency:** preserve the concurrent native
   WordPress work incorporated at `9234851`. Remaining migration domains and
   activated routes are listed in [native workflows](native-wordpress-workflows.md).
   These owner proxy improvements do not claim that every workflow is native PHP.
5. **Production promotion:** this follow-up is scoped to Staging under `AGENTS.md`.
   It does not authorize promoting this entire release to production. The backend
   stays pinned so documentation/frontend pushes do not silently redeploy it.

No live Google inference request, supplier-price decision, reference activation,
catalog replay, customer/order mutation or production deploy was performed during
this release verification.
