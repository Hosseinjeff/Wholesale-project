# Native WordPress workflows

The owner's latest instruction keeps bot-related processing on Railway and moves
all other workflows to WordPress on both Staging and production. This supersedes
the earlier assessment of moving the bot itself. No separate dashboard or second
business ledger is introduced.

The independent runtime MU-plugin files contain native PHP domain functions and a
gateway for the existing WordPress dashboard requests. Each completed method/path
is activated explicitly through the protected workflow configuration endpoint.
Unported methods keep their existing execution path. An activated method fails
locally if its dependencies fail; it never silently retries a write on Railway.
Native writes require active WordPress state custody and an existing explicit
WordPress business-owner identity. Config/status/shadow requests use the existing
runtime HMAC, remain private and never return connector credentials.

Current execution: both sites use WordPress state, 23 native dashboard methods,
local supplier outbox processing and WordPress-owned publication. Both Railway
publication filters are deployed. The reference refresh port below is the next
activation checkpoint; order and catalog-conflict workflows remain incomplete.

Implemented in source as of 2026-11-17:

- Existing SQLite schemas and local transactions, shared cross-runtime leases,
  compatible JSON revision hashes and immutable queue identities.
- Native Google Sheets OAuth/REST connector with private configuration outside
  the web root. Configuration is not yet transferred or activated.
- Inventory receipt/replay, correction, adjustment, hold/release, reservations and
  clearance calculations with the existing audit and revision rules.
- Paid-order allocation and procurement proposals, including protected payment
  state, fixed-pack rounding, prior-source checks and idempotent terms decisions.
  Order workflow actions and dashboard integration remain to be ported.
- Native operations queue pagination/worker status and reference snapshot/row and
  exception-list reads. Inventory/clearance and sender-enrollment HTTP contracts.

`tests/native_domain_parity.php` compares native results against synthetic golden
fixtures produced by the existing Python classes: 51 checks pass. Cases include
Unicode receipts/hashes, reservation replay, stock conflicts, paid-order source
changes, cancellation, supplier pack rounding, stable queue pagination, reference
preview/coverage, and gateway owner/method/body/cursor guards. Fixtures contain no
business data and call no AI or external APIs.

Deployment and activation are separate. Record actual per-site receipts below;
source presence does not mean a route has switched. Production's old inactive
state candidates must never be used without fresh transfer verification.

## Verified rollout

`d0e4390`'s seven native MU-files were uploaded and read back on both sites. Both
hosts run PHP 8.3.36. Six Staging native GET responses matched their existing
Railway counterparts: operations, reference snapshots, reference exceptions,
stock lots, stock events and clearance proposals. Native requests took 0.69–1.67
seconds versus 5.78–16.04 seconds for the existing Railway reads. The real signed-in
owner dashboard's same-origin AJAX queue request returned 200 in 0.949 seconds.

Both sites now enable those six GETs and the five POSTs for stock receipt, purchase
date correction, stock actions, clearance proposals and sender enrollment. No real
inventory decision or proposal was submitted during verification. Production was
enabled only after its fresh state cutover, atomic commit/replay and six native read
checks. Private result bodies, import proofs and rollback copies stay outside Git.

The next source checkpoint also contains the native structured-reference parser,
field-aware incremental store, coverage checks, review/activation and matching
Python-compatible float/hash encoding. These functions are not yet activated for
reference refresh or writes. Synthetic Python/PHP parity now covers 292 cases,
including unchanged source, price-only/title-only changes, stable ID/SKU changes,
reordering/removal, snapshot IDs, activation and raw-source custody. A protected
server-to-WordPress connector handoff is implemented and has three focused tests;
it still needs its own scoped backend deployment before use.

The connector handoff has now succeeded on both sites. Staging's current successful
backend is `cfd6515` (`c78e7cf4-c4ce-4677-abf6-5597f4c237dd`), incorporating the
native handoff and the latest owner-read fixes. Live's handoff backend `1b046a3`
deployed successfully as `47424989-f352-4bb0-b27b-d320778d02a0`. No credentials
were returned or copied into the workspace. Protected connector check reports only
table row/column counts. Native Sheets reads preserve the existing formatted-value
contract; raw reads are explicit and cached separately for write verification.

The product-status port now covers live Products/Offers reads, source identity and
provenance checks, supplier freshness/revision calculations and immutable status
capture/history. It preserves the existing business rules and private-field
exclusions. There are 327 synthetic PHP/Python checks. Product-status GET/POST
handlers exist but require read-only per-site comparison before route activation.

After the live cleanup, Railway control-plane metrics showed current disk usage
0.0489472 GB versus a one-hour high of 0.404676608 GB, and current RAM 0.065241088 GB.
The production service was independently observed `Sleeping`, with no pending
deployment, warnings or recent failures. These are observed values, not resource
limits or a guarantee that memory will not rise during bot work.

Current product-card reads have now been switched on both sites (13 native
method/path pairs). Staging's 132 cards exactly matched the existing endpoint;
the native request took 4.688 seconds versus 14.206 seconds. Live's native connector
read 125 Products and returned all 125 validated cards. Its old Railway current-card
endpoint timed out after 120 seconds, so no live equivalence claim is made. The
same implementation passed the cross-language fixtures and Staging comparison;
live was enabled after native row-count/shape/privacy checks. Status history/capture
remains the existing audit interface, separate from the live card view.

The supplier verification port now includes revision-bound manual decisions,
command replay, audit history and canonical shared queue encoding. Its native
outbox reuses the existing WordPress MySQL decisions and receipt validator, obtains
the exact recorded owner from the command's decision, and processes only activated
domains. The existing minute scheduler leaves supported commands in WordPress and
wakes Railway only for unported outbox domains. Native domain parity is 352 checks;
the scheduler has 16 checks, including no Railway wake for local outbox work.
These new supplier/outbox handlers still need their own Staging deployment and
read comparison before activation. No pending review was automatically approved.

The supplier/outbox MU-files were read back on both sites from `afacc17` (live
source `52d2859`). Activation remains off: the Staging comparison found 23 cells
whose literal string `"0"` had become blank through PHP truthiness. A shared
Python-compatible fallback helper and a synthetic zero-price/zero-percent case
correct that representation. The new publication projection functions preserve
the existing SKU, source-link, freshness, conflict, supplier-availability and Shop
settings behavior; they do not publish or change execution ownership yet. Native
parity now has 379 checks. Finish the corrected supplier comparison before enabling
its two methods and local outbox processing.

Corrected supplier verification is now enabled on both sites (15 native methods).
Staging's 130 outstanding supplier entries matched the existing endpoint exactly;
native read time was 7.486 seconds. Live's native entries passed shape/state checks;
the old live supplier endpoint timed out after 90 seconds. No live comparison claim
is made. Both sites use the existing WordPress outbox/decision tables and original
recorded actor; only already-authorized pending commands are eligible.

The publication worker checkpoint adds `molavi_state_executors`, operational metadata
beside the canonical queue. Default ownership is Railway. WordPress can own only a
fully implemented job kind; configuration rejects active leases and requires backend
filter version 1. Python claims, explicit job-ID claims and next-wake calculations
exclude WordPress-owned work. WordPress due checks ignore local worker heartbeats
when deciding whether bot work needs a Railway wake. No second queue is created.

The PHP publisher reuses each site's existing `molavi_apply_catalog`, settings and
image functions, protects the shared publication lease and all review holds, checks
the Sheet digest before/after publication, and leaves source changes for a fresh
idempotent job. Photos still come from the bot's existing media endpoint. Partial
image progress does not exhaust failure attempts. Legacy bot-triggered publication
queues work rather than synchronously calling WordPress back, which would deadlock
if WordPress requested a photo from that same single-worker bot request.

The existing minute scheduler observes due Sheet changes and drains publication
locally after the kind is assigned to WordPress. Idle ticks create no worker rows.
Checks: 395 PHP native cases, 18 scheduler checks, 19 state-host checks and 66 focused
Python publication/queue/runtime/state checks. This checkpoint still requires scoped
backend deployment, filter-version read-back and explicit per-site ownership switch;
do not infer publication ownership from source presence.

Staging backend `7b49a63` deployed as `dfc4e5ac-ce88-4b6e-8d1d-c0b8133dc38d`,
terminal SUCCESS. Runtime read-back confirmed filter version 1, WordPress state and
zero Railway data files. Its publication kind was then atomically assigned to
WordPress. Live's equivalent `ce67ccb` MU-files are uploaded/read back; its exact
backend source change is staged but not yet accepted. The executor status endpoint
reports native observation/job/outbox counters only. Enabling native publication
makes its existing Sheet clock due so real minute cron verifies the first local
observation without a synthetic order or invented product.

Remaining migration domains: reference fetch/incremental activation and matching;
catalog/Sheet reconciliation and guarded publication; current product and AI review
read models/commands; supplier freshness and publication recovery; order workflow
actions/substitutions and native order Sheet writes; native non-bot job scheduling
and bot-only Railway queue claims. Keep the existing policies and receipts when
porting these domains. Do not claim the whole migration is complete yet.

The source incorporates `466c71d`/`7b87352`, preserving Staging's deployed text-only
bot and free Google routing work. Its Railway source pin must not be rolled back to
the earlier checkpoint in `wordpress-state-executor.md`.

The native reference lifecycle now includes bounded public-source fetches, strict
pagination/currency/ID coverage, raw node comparison, unchanged-source checks,
immutable staging, existing initial review and incremental activation, ReferencePrices
cell projection, approval inheritance, affected-product reconciliation, durable failure
incidents and owner exception decisions. The existing minute cron can run the daily
refresh locally once its POST route is enabled. Activated work never falls back to
Railway. Interrupted activation/projection can resume without a second source fetch.

Validation: 411 native domain parity cases, 54 reference runtime cases (including six
Python projection fixtures, receipts, cooldown, source revision, concurrent lease and
interrupted-fetch recovery), and 21 scheduler checks. The private reference/check
endpoint fetches and compares in memory without changing raw records, snapshots,
Sheets or decisions. This source checkpoint has not yet enabled either new POST
route. Confirm live source coverage and record per-site activation separately.

Production publication also reached terminal SUCCESS (deployment
`113a2b6a-46a1-45cc-ba44-44f49600d9ed`) with filter version 1 and WordPress state.
Its executor is assigned to WordPress and real cron observed the unchanged Sheet
locally. Staging's real cron preserved the first publication job's existing review hold.
Both Railway data directories were verified empty.

Both source checks completed locally with no business writes: 3,372 source products
across 34 pages, with complete coverage. Staging compared stable raw IDs; live also
identified the expected metadata upgrade from its older active reference format.
The reconciliation writer batches multiple products per Sheets request, keeps each
product in a single atomic request and verifies raw cell receipts before completion.
Activation recovery distinguishes an already committed active ID from a new
activation; failed projection/reconciliation retries locally without refetching or
waking Railway. Route activation is still off at this source checkpoint.

Both sites now enable reference snapshots POST and reference decisions POST (17
native method/path pairs). Enabling local refresh makes the existing real cron
check due. Staging's first cron attempt correctly rejected changing source totals
and kept the previous active snapshot; public API checks confirmed the source
itself grew from 3,372 to 3,394 products. Its subsequent native refresh completed
with 3,394 raw records, verified projection and complete reconciliation; no main
product approvals required updates. Production is enabled and awaits its first
local refresh receipt. The final fetch refinement uses eight bounded requests and
restarts an inconsistent generation once from page one; four additional synthetic
checks verify recovery and the retry limit. No partial generation is staged.

Railway control-plane observation during this work: production wholesale was
Sleeping with no issues; Staging RAM was 0.08275968 GB and disk 0.01081344 GB.
The separate FollowUP-bot service is outside this repository and remains untouched.

Reference cutover verified on both sites: active, projected and reconciled IDs match;
each now has 3,394 authoritative raw source records and zero pending raw rows for
the active snapshot. Live's real cron completed the metadata upgrade and retained
one affected approval within the existing 5% rule (zero new review cases).
Staging's native retry completed after its earlier safe pagination rejection. Both
new reference methods remain enabled locally; the bounded-generation retry source
was uploaded and read back from `fa6fb71` / live `1d459bd`.

The next order foundation contains native supplier/customer/delivery transitions,
refund limits, replacement consent and payment receipts, cancellation recovery,
stock reservation/fulfillment and existing ledger event identities. 42 synthetic
Python workflow scenarios compare results and all nine related tables (420 checks).
Four current-Sheet catalog cases and three owned-stock shortlist cases add seven
checks. The catalog reuses the existing storefront pricing function through an
optional settings argument; existing storefront callers keep their current behavior
and all 20 customer runtime checks pass. No order route or order execution ownership
is activated at this checkpoint; native order Sheet writing and integration are next.
The protected status endpoint reports Unicode normalization/casefold capabilities
for checking host dependencies before the order rollout.

The order integration source now includes the owner GET/POST contract, current-Sheet
replacement price bounds through the shared storefront core, verified Orders/OrderLines
upserts, native order job leases and per-order FIFO, and Woo completion receipts.
Checkout persists its existing durable task without waiting for Sheets; real minute
cron performs the write. Matching paid/initial Woo metadata is acknowledged only
after verified writes. An older receipt cannot clear a newer pending Woo event.
Only a bot notice dispatch remains on Railway, preserving the original per-recipient
notice keys and allowlists. Both hosts have native Unicode normalization/casefold.

Checks: 434 native order workflow/API checks; 46 writer/receipt/queue checks; 411
existing native-domain checks; 21 scheduler checks; and 35 focused Python runtime,
notice, paid-order and follow-up tests. Synthetic checks send no messages or model
requests. The Railway runtime advertises `native_order_notice_version=1`; order
ownership cannot switch until its backend filter and notice version are verified.
WordPress/test/archive files are excluded from the bot container build. Order route
activation and execution ownership remain unchanged at this source checkpoint.

Staging order cutover: backend `302dcfe5d1c2bad45ad22501c212f09cb1eb40af`
deployed as `e9fcf0db-0262-4e9f-b931-fb44c5463da8`, terminal SUCCESS. Native and
legacy paid-order GET responses matched exactly (both currently have zero plans);
nonempty workflows and writes are covered by the synthetic fixtures. Runtime
filter/notice versions were read back before assigning order execution to WordPress
and enabling paid-orders GET/POST (19 native methods). Both sites were checked for
zero active bot workers/order leases before their scoped releases. Both support
SQLite JSON, Unicode normalization and casefold. Live's equivalent `711b7ca`
backend reached terminal SUCCESS as `d20dd0b4-5fea-4a98-bf1d-3fd075c6013f`;
its order executor remains on Railway until the live comparison/switch receipt.

The final native order probe exercises the actual WordPress HTTP filter using an
invalid, authenticated payload. It expects a local 400 with the WordPress executor
header and creates no order, queue task, Sheet write or bot message. Four additional
filter checks pass; this WordPress-only follow-up does not change either backend pin.

Both order cutovers are now enabled (19 native methods): live's paid-order response
also matched the existing endpoint exactly, with zero existing plans. The protected
probe then identified an older live-site legacy order configuration gap. The native
Woo adapter is therefore wired directly to the local durable queue: it needs no
legacy bot URL/notify secret and performs no checkout HTTP call. Its missing/failed
local dependencies retain the existing Woo retry behavior. The old HTTP adapter
remains only for an unassigned executor. Saved immutable payloads are reused without
rebuilding an unused current snapshot. Six further tests exercise the actual Woo
entrypoint with empty legacy configuration, verified metadata completion and replay
without external I/O. Source cutover is enabled; deploy this final shop/runtime
follow-up and repeat the protected probe before declaring the live entrypoint ready.

The final order shop/runtime follow-up is uploaded/read back on both sites from
`9c131b8` / live `f5ca6a6`. Protected probes verify the native entrypoint with zero
writes on both. Staging also verifies its legacy HTTP interception; live has no
legacy order settings and now needs none. A read-only Woo REST audit found zero
existing production orders, so there is no missed historical handoff backlog to
replay or historical stock allocation decision to make. Both recent runtime receipts
confirmed on-demand mode, WordPress custody and zero Railway data files.

Publication recovery now has native hold context reads, single/bulk owner replay,
source-verified hold reconciliation and catalog/backlog synchronization with the
existing audit receipts. It preserves the explicit review choices, raw source count
and AI-case proofs, complete-image requirement, before/after Sheet digest and exact
failed-receipt selection. No real review was resolved during implementation. Nested
publication operations share and renew one request-owned lease, then release it once.
74 synthetic Python/PHP checks cover context privacy/truncation, atomic selections,
incomplete source rejection, partial sync, changing Sheets and audit states. Existing
411 native-domain and 52 order writer checks still pass. New recovery methods are
implemented but not activated at this source checkpoint; compare and record rollout
separately. Bot source pins remain `302dcfe` / live `711b7ca`.

Publication recovery is now enabled on both sites (22 native method/path pairs).
Staging's one held batch and live's two held batches matched the original Python
read implementation exactly against atomic, read-only WordPress snapshots. The
comparison made zero Railway HTTP requests; native responses took 2.547 / 2.587
seconds. No review, batch replay or backlog reconciliation was submitted. Live's
first upload hit a transient cPanel save failure; the bounded identical retry
completed with all three MU-files uploaded/read back from `5d12521` (Staging
`de3defb`). The original 31 publication/operations tests pass with the repository's
tests directory on PYTHONPATH. Both wholesale Railway services were independently
observed Sleeping with zero issues and no pending platform changes during these
WordPress reads. The separate FollowUP-bot remains outside this repository.

The intake-to-store reconciliation port is implemented next. Its pure report
renderer preserves receipt/current-offer/derived-offer joins, price-order counters,
publication decisions, ambiguous identities, held products, legacy SKUs, truncation
and the explicit not-measured AI accuracy note. Six synthetic report fixtures and
three original queue receipt joins pass; seven controller checks verify in-process
Woo pagination, unavailable responses, private identity reads without a legacy
secret and the ten-page ceiling (16 checks). Per-receipt product detail/visibility
lists are sorted for deterministic output; comparisons normalize the Python set
iteration order. The Woo Store API runs through rest_do_request rather than HTTP
and the existing local identity snapshot supplies durable matches. The GET handler
is available in source but not enabled at this checkpoint. Its private rollout must
validate source/receipt counts and privacy before activation.

Reconciliation GET is now enabled on both sites (23 native methods). Staging's
read checked 132 product rows / 2 intake receipts in 6.919 seconds; live checked
125 product rows / 123 receipts in 7.586 seconds. Both in-process Woo reads and
catalog projection checks succeeded. Public Woo SKU counts were 0 / 25; these are
SKU counts, not an assertion about the total visible product count. The existing
private identity snapshot also participates in matching. No raw source text or chat
IDs are exposed in receipt rows, no business write/review was submitted, and no
Railway HTTP call is made by this read path. Uploaded/read-back sources are
`777c37a` / live `f630d6b`. Remaining business controllers include Sheet conflict
resolution, reference-item review/decisions and AI review read/decision models;
actual extraction/re-extraction/suggestion execution stays with the bot runtime.

Sheet conflict GET/POST and the shared Offer-to-Product derivation core are now
implemented locally. The port retains source revision and fresh Sheet-cell checks,
server/preserved field restrictions, verified single-cell writes, audited choices,
publication queuing and response-loss retries. A stale source is rebuilt through
local three-way reconciliation, preserving independent/concurrent Sheet edits and
superseding obsolete conflicts. Decimal comparisons retain 28-digit Python Decimal
normalization rather than converting cells to floats; supplier identity casefold
now matches Python. Reference decisions, inherited approvals and package-unit
conversion participate in the same shared derivation. 695 synthetic parity checks
cover pure calculations, review inheritance and 23 complete controller scenarios;
18 original Sheet tests and the prior 411 native-domain checks pass. No actual
review was submitted. Deployment/read comparison and activation are tracked next;
bot source pins remain unchanged.

Sheet conflict GET/POST are enabled on both sites (25 native methods). Current
open lists matched the original implementation against atomic, read-only WordPress
snapshots: both contain zero conflicts, with native reads taking 1.815 / 1.222
seconds and zero Railway calls. Positive decisions/concurrency remain synthetic
parity tests; no real review was resolved. Both first cPanel uploads encountered
transient errors; identical bounded retries uploaded/read back all four MU-files
from `642c4bf` / live `a7b449d`. The existing local outbox now includes Sheet
conflict commands automatically.

The bot now includes a bounded, nonsensitive capability receipt in its existing
schedule callback and owner runtime report. It identifies the actual extraction
and suggestion policies plus configured/image flags, so native review reads need
no bot HTTP calls. The authenticated WordPress receiver validates exact fields;
older scheduling observations cannot overwrite a newer receipt. This is a bot
boundary change and must be cherry-picked onto each environment's separately
pinned bot source, preserving live image extraction and Staging text-only behavior.
No extra HTTP request, model call or polling loop is introduced.

Reference-item review is now ported in source: local candidate ranking, current
Product/Offer case construction, receipt attachment, strict audited manual/no-match
choices, AI-card reference assignment and verified Product projection. Suggestions
only enqueue the existing bot task when the receipt advertises that executor; live
keeps it unavailable. Matching uses the same normalized Unicode Indel ratio as the
declared RapidFuzz runtime dependency, with a signed-safe bitset fast path and a
request-scoped reference index. Scores remain suggestions, never approvals. 189
synthetic checks cover ranking/signatures, 70 Unicode similarity pairs and 21 full
controller scenarios; the original 11 reference item/matching tests pass. The local
validation environment initially lacked the declared RapidFuzz dependency; it is
now installed for runtime parity. Existing 695 Sheet checks still pass. This source
checkpoint is not yet an activation receipt.

Bot policy release `430916b` on the separate Staging release branch deployed with
terminal SUCCESS as `7fa1c72e-e749-46ca-8981-757faf842117`; production's narrowly
scoped source `cd13c49` deployed with terminal SUCCESS as
`35841ae2-50f6-4c57-9719-14e479b96684`. The native receiver/scheduler files were
uploaded/read back first on both sites. Production intentionally has no newer
suggestion module: its capability is null and no Staging bot features were copied.
Each environment keeps its original image/text extraction behavior and on-demand
settings. Future bot scheduling callbacks refresh the local policy receipt without
an additional HTTP request. Frontend/PHP-only commits must leave these pins fixed.

Both stores now handle Sheet conflict reads and decisions inside WordPress. The
local queue returned the same empty conflict list as the original implementation.
The reference item review read also matched on both stores, including two open
Staging cases. It returned in 4.375 seconds on Staging and 6.983 seconds on live.
The WordPress routes were enabled after these checks.

The AI review migration handles case reads, owner decisions, dismissals, extraction
previews, progress, cancellation and queue creation in WordPress. The bot processes
the existing extraction jobs when WordPress sees work due. WordPress queues the
existing publication receipt after a run finishes with written offers. Synthetic
tests cover the original text and image policies, source revision checks, publication
holds and retries. This checkpoint records the implementation before live verification.

Both AI workflows are active in WordPress with 30 owner methods enabled on each
site. The normal authenticated dashboard request returned 73 open cases and eight
recent decisions on Staging, and 33 open cases and 15 recent decisions on live.
The case and preview responses matched the original implementations. Matching now
uses the structured reference package count and retains supplier title inference
on the offer side. Both real crons reported current timestamps with no queued or
running jobs before activation. No review or extraction was submitted for testing.

The old Railway URLs can forward to the WordPress handlers after their existing
authorization. The storefront itself already runs in WordPress. The migration
inventory now reads both active stores locally. The standalone buying desk refresh
route is retired in WordPress-hosted mode. It had no requests in the seven-day
Railway traffic sample, and Telegram still prepares its prompts when it handles a
supplier post or a reply. The remaining old order, publish, catalog and cart URLs
use the WordPress commerce adapter to preserve callers without a second business
executor.

## Final live cutover

Both wholesale Railway services are on demand with WordPress custody and zero
business data files. Railway's final deployment IDs are
`8c88b55d-a710-4a11-a21a-63bfbb6a4e74` for Staging at `07b3d3c` and
`ba8463b9-9a26-41f9-8227-33466eaf39a4` for live at `1325779`.
Both deployments reached SUCCESS. The two empty mounts were detached after fresh
runtime checks. Railway retained both unattached volumes for recovery.

Legacy catalog and cart requests on both URLs returned WordPress receipts. The
live catalog returned 25 products. The old desk endpoint returned retirement status.
An unsigned owner request and an unauthorized publish request were rejected. Eight
synthetic quotes matched the original calculations. Two older Staging pricing tests
also fail on the prior runtime revision.

The installed dashboards defer Telegram photo downloads until the reviewer opens
the source image. Both bundles were uploaded and verified. The final protected
WordPress reads found 30 active owner methods per site, no pending Railway wake,
no queued or running jobs, fresh reference state, and current minute cron receipts.
The production and Staging review decisions and existing business holds were
preserved.
