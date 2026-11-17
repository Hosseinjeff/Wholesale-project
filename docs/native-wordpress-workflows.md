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

Current execution: both sites use WordPress state, 15 native dashboard methods,
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

Validation: 411 native domain parity cases, 50 reference runtime cases (including six
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
