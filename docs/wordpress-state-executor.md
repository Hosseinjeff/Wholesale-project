# WordPress state ownership and stateless Railway processing

2026-11-16, Asia/Tehran. The owner explicitly requires WordPress to own persistent
data and all feasible local functions. Railway receives only the records it needs
for Python processing and retains no local business database.

## Custody and execution boundary

`molavi-state-host.php` is an independent MU-plugin, deployed alongside the existing
runtime host. It uses PHP's native SQLite3 support and stores the two existing ledgers
under an explicitly configured `MOLAVI_STATE_ROOT` outside `public_html`. This preserves
all IDs, schemas, revisions and decisions without reimplementing business rules or
converting the data into a second competing ledger. These are WordPress-hosted private
SQLite files, **not MySQL tables**. Host backups must include this private directory.
The existing WooCommerce/MySQL records and WordPress review audit/outbox keep their
existing roles. No WordPress storefront plugin is replaced by this component.

The PHP host performs local database reads, writes, atomic commits, lease coordination
and due-work inspection. Python uses one connection provider across its ledger classes.
It requests rows through the scoped private state API; database files are never
downloaded to the executor after cutover. Only empty path markers and temporary lock
metadata may exist under `/tmp`. The provider refuses a persistent `/data` replica and
never silently falls back to local writes if WordPress is unavailable.

Transactions collect write statements into one program. A read previews that program
inside a rollback transaction on WordPress. Its result fingerprints are revalidated
when the complete program commits atomically. A stale read refuses the write, and a
transaction ID plus stored receipt makes a lost commit response safely retryable.
Trigger clocks are fixed for each transaction. Schema metadata is the only bounded
worker cache; source/product records are not cached globally. Cross-executor publication,
reference activation and refresh locks live on WordPress and are renewed by state calls.

The API requires an HMAC-derived state-specific credential, separate from the runtime
schedule credential. It admits only the pipeline/reference stores, limits statement
and body sizes, binds scalar parameters, blocks attach/detach/extension loading and
requires private filesystem paths. Neither credential nor record contents belong in Git.

## Transfer and verification

1. Deploy the provider with the existing local mode intact. Configure the private
   WordPress directory without changing runtime authority.
2. The owner-only transfer endpoint refuses active requests/background work, briefly
   freezes legacy intake (HTTP 503 triggers Telegram retry), and takes consistent
   SQLite backups. Reads are streamed in bounded chunks.
3. Upload chunks to WordPress with a stable transfer identity. Verify full SHA-256,
   SQLite quick-check and every business table count. Keep the exact transfer copy on
   WordPress for recovery. A partial/mismatched import cannot replace an active store.
4. Pin the tested backend and set `PIPELINE_STATE_HOST=wordpress`, with both configured
   state paths under `/tmp`. Verify owner reads, current reference identity, queue state,
   source revisions, enrollment, scheduler receipts and a no-op/idempotent write.
5. Activate native WordPress due-work checks. Only after verified import receipts and
   the successful runtime cutover may the owner endpoint remove legacy database files
   from Railway. Cleanup refuses missing/mismatched transfer receipts.

After new WordPress writes occur, rollback must preserve that latest WordPress state;
do not repoint an old backend at the stale frozen copy. Keep the connection provider
when rolling back application code, or perform a new verified transfer in reverse.

## Verification and limits

Focused tests cover PHP-native import integrity, rollback previews, atomic commit,
response-loss replay, changed-read rejection, deterministic triggers and competing leases.
Python tests cover row/cursor compatibility, read-only programs, deferred batched writes,
compare-and-swap counts, schema-only caching, no local fallback and source-preserving
backups. Existing queue/bot/runtime tests remain applicable. Three Staging reference
snapshot expectations also fail against the unchanged pre-provider implementation
(two coverage expectations and repeated review); they are not caused by this transport.
The production reference snapshot suite passes. Do not claim that every legacy suite
passes or that native PHP has replaced Python extraction/normalization.

Actual rollout IDs and custody verification must be recorded in the project handoff.
Until those receipts exist, this document describes the implementation, not a claim
that the live migration is complete.

## Current cutover checkpoint — 2026-11-17

Staging is WordPress-hosted and its legacy Railway database files have been removed.
The transfer verified full hashes and all 29 business tables (16 pipeline, 13 reference).
Native due checks are active; no-op commit/replay and both approved sender records were
verified. Deployment `134ec2a7-8e37-493d-809f-68e73d19bd91` from `a2487e8` is
successful. Queue, reference and AI review reads returned 200; batched AI decisions
replaced per-offer remote calls (the checked AI read completed in about 38 seconds).
The compressed-request MIME fix `6e6da4c` is pushed and staged on Railway, but its
accept-deploy call was interrupted and was not applied. Finish that pending source
pin before relying on large compressed state writes.

Production remains on local state with the idle-memory guard. Its current successful
backend is `469fa97`, deployment `c2752154-3104-4d87-b085-05b90ffc7c97`. Both
databases were copied to the protected WordPress directory and full checksums/table
counts matched. The copy used compressed binary requests; renewed protection also
verified that the frozen source had not changed. **The live state-mode switch was
not applied.** After the deployment-tool interruption, the temporary live freeze
was released to resume normal processing. The WordPress production copies are
inactive and can now be stale. Never switch production to those copies blindly:
freeze again, verify the current source, archive any obsolete inactive candidate,
and transfer/compare fresh data before enabling WordPress state. Do not remove the
live Railway database or volume until the actual cutover and read/write checks pass.

The production MIME fix is pushed as `cbf54f2`; it is not deployed. All private
manifests, binary copies and receipts are under `/tmp/molavi-memory-private`, outside
Git. The independent runtime/state MU-plugins on both sites were read back from
revision `a2487e8`. Native housekeeping is tested, preserves review-needed payloads,
and accepts WordPress's string-valued retention option. It uses the verified existing
30–365 day setting supplied by the executor's schedule callback.

The full-runtime removal assessment is in `wordpress-only-feasibility.md`. No claim
is made that the remaining Python bot, extraction, matching or synchronization logic
has already been ported to PHP, or that the separate FollowUP bot has moved.
