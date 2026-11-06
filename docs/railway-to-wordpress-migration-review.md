# Railway to WordPress workload review

**Date:** 2026-11-06 (Asia/Tehran)
**Status:** The first WordPress-owned Sheet-conflict ledger and Railway consumer are
live in production. The live outbox connection is verified with zero due commands;
the active Telegram retry queue remains on Railway.

## Finding

The practical target is WordPress/WooCommerce as the home for product and business
state, with Railway retained as a small Python processing worker. Moving the Gemini
extraction runtime itself to shared WordPress hosting is not the first saving: the
last seven days of Railway metrics show modest average load, with one higher peak
whose timing and cause are not identified by the aggregate.
The larger architectural waste is duplicated persistent state across Railway
SQLite, WordPress review records, Sheets, and WooCommerce.

## Live measurements

Railway metrics returned 2,017 samples over the last 168 hours:

| Service | CPU average / peak | Memory average / peak | Disk metric now / peak |
|---|---:|---:|---:|
| `Wholesale-project` | 0.0037 / 0.7783 | 146 MB / 415 MB | 142 MB / 142 MB |
| `FollowUP-bot` | 0.0010 / 0.0493 | 60 MB / 127 MB | 0 MB / 0 MB |

`Wholesale-project` has one replica and a 500 MB persistent volume at `/data`.
Railway's disk metric is service-level; it does not identify which SQLite file or
table uses the space. The current 142 MB reading therefore cannot be assigned to
the pipeline database without a read-only database inventory.

Moving records can reduce Railway's persistent business state and cross-system
coupling, but it is unlikely to materially reduce CPU by itself. Average CPU is low;
the aggregate does not show how long the 0.78 peak lasted or what caused it. Moving
Gemini and the Python normalization stack onto shared PHP hosting could add timeout
and retry risk without evidence of a compute bottleneck. Keep one Railway worker for
now and right-size only after measuring what remains.

The storage release lowers completed-payload retention to 30 days by default
(configurable 30–365) and runs compaction on worker startup, daily, and after a
successful handoff. It preserves message IDs, URLs, timestamps, and content hashes;
failed, waiting, and review-needed jobs remain intact. Whether the Railway volume file
shrinks is still unverified: SQLite compaction reuses freed pages, and a later
`VACUUM` would require a verified backup and maintenance window.

In the returned seven-day HTTP log sample, the owner dashboard generated cross-origin
preflights and calls to Railway; the sample included 75 `OPTIONS` and 35 successful
`POST` requests to supplier-price evidence. It also included 11 `GET /api/shop` and
2 `POST /api/cart` requests. These are small volumes. The admin UI still uses Railway
as its read/write API even though WordPress already records review context and
decisions locally.

## State currently held outside WordPress

The mounted `PIPELINE_DB_PATH` SQLite database is more than a queue. Code creates
tables for Telegram inbox/jobs, publication holds/resolutions, Sheet conflicts and
projection state, supplier-price verification history, AI confirmations/dismissals,
product-status snapshots, paid-order plans/decisions, inventory lots/reservations,
clearance proposals, and order-workflow events. `REFERENCE_STORE_PATH` holds
reference snapshots, activations, refresh state, and reference decisions.

WordPress already has a private owner-review event table and WooCommerce owns the
published products and orders. The owner-review table currently acts as an audit
copy: the browser records pending decisions there and then calls Railway, whose
SQLite ledger still drives the operational state. Sheets remain the operational
product book and source-message archive; WooCommerce receives the shelf projection.
This is the main split to remove.

The current WordPress table is not yet a normalized decision store: it keeps
`context_json`, `decision_json`, and `receipt_json` together per event. Before
expanding it into the operational home, keep current state in indexed domain tables
and use the event table as a compact audit log. Store source IDs, revision/hash,
decision, actor, timestamps, and processing receipt there; avoid duplicating full
source posts when Sheets remains the archive.

## Recommended ownership

| Data/work | Target | Reason |
|---|---|---|
| Product master, variants, publication state, owner decisions, conflict resolutions, supplier-price verification, reference approvals, inventory/procurement and fulfillment events | WordPress custom tables plus WooCommerce CRUD/meta where appropriate | These are lasting business records and dashboard decisions; the user already wants them stored in the WordPress database. |
| Raw supplier post and immutable extraction evidence | Keep in Sheets during migration; later project into WordPress without deleting the archive | The Sheet is still a supported manual-edit surface, and its bidirectional conflict rule must keep working through cutover. |
| Active inbox, retry/lease/attempt metadata, idempotency keys | WordPress Action Scheduler/custom outbox only after the site's scheduler is verified; until then keep the small active queue in Railway | A delayed WP-Cron run can strand Telegram intake. Keep retry durability ahead of volume reduction. |
| Gemini extraction, normalization, source validation, Sheet synchronization and idempotent Woo publication worker | Keep on Railway initially | Python dependencies and time-bounded network work already run there; measured averages are low and peaks are batch-bound. |
| Public catalog and cart quote | WordPress/WooCommerce | The storefront is already WordPress-native; retire Railway endpoints only after confirming the live Elementor pages and any outside clients no longer depend on them. |

## Efficient migration sequence

1. **Make WordPress the decision authority first.** Add normalized WP tables for
   current decisions and operational workflow state. Each dashboard action commits
   an idempotent WP outbox event before sending it to Railway. The Railway worker
   consumes undelivered commands and writes a verified receipt back; WP remains the
   decision record and Railway performs the Sheet-side effect. Keep the existing
   review-event table as an audit trail. Preserve
   the agreed Sheet rule during transition: Railway's computed value wins unless an
   explicit manual Sheet edit is detected, in which case that edit updates WooCommerce
   through the WP-owned state and sync path.
2. **Move ledgers by domain, not by copying the whole SQLite file.** Start with
   supplier-price verification and sheet conflicts, then reference decisions and
   product publication state, then order/inventory records. Migrate with stable IDs,
   source revisions, row counts, checksums, and a reversible dual-read comparison.
   Do not delete the SQLite copy until the WP projection matches and rollback is
   tested.
3. **Shrink the queue payloads after source persistence.** Once the original post is
   confirmed in MessageData, retain only batch/message IDs, source URL/time, hashes,
   and retry metadata in Railway. Preserve raw payloads only when Sheet persistence
   failed or the source is not recoverable. Keep active holds/review context available
   from WordPress/Sheets before compacting it.
4. **Move the durable intake queue only after proving scheduling.** WooCommerce's
   Action Scheduler can store jobs in WP database tables, but the host must run a
   real cPanel cron or another supervised runner reliably. Verify installed scheduler
   health, overdue actions, PHP time/memory limits, database backup/restore, and webhook
   response time. WP-Cron alone is traffic-triggered and is not enough for dependable
   Telegram retries. If these checks fail, keep only inbox/job metadata on Railway.
5. **Remove duplicate Railway customer APIs after a usage check.** The current log
   sample shows little `/api/shop` and `/api/cart` traffic and the checked-in storefront
   code is WordPress-native. Confirm the live Elementor page, caches, and external
   callers before retiring these endpoints.
6. **Measure the remaining volume and CPU, then right-size Railway.** After ledgers
   move, verify the attached volume's actual file sizes, free pages, and growth. Back
   up before any SQLite vacuum or volume reduction. Keep Railway at one worker replica
   until webhook retries and concurrent owner actions pass outage/restart tests.

## Required proof before moving intake/compute

Read-only cPanel UAPI inspection found zero configured cron entries. The available
WordPress login did not establish an admin session, so Action Scheduler backlog,
PHP limits, database backup/restore, and table-level Railway file sizes remain
unverified. Those are the gates for moving the durable inbox or AI processing to
WordPress. Keep Python extraction and the active retry queue on Railway until those
checks pass; move business ledgers in reversible, verified domains while preserving
Sheets as the source archive and manual-edit surface.

## Expected result by phase

The first phase removes the dashboard's dependency on Railway as the authority for
owner choices and moves lasting decisions into WordPress. The next phases move the
remaining business ledgers and leave Railway with a small active work queue plus the
Python worker. The largest safe Railway reduction comes from dropping duplicate
business ledgers and old source payload copies; a CPU reduction is not the expected
benefit. Only after cPanel cron and Action Scheduler pass retry, restart, and outage
tests should the Telegram inbox and retry metadata move into WordPress as well.

The storage change affects only duplicate raw text in successfully completed Railway
batch receipts after the configured age. Source posts remain in Sheets, and active,
failed, waiting, and review-needed work is retained. This slice does not move product
records, source messages, the active Telegram queue, or the other Railway business
ledgers.

## First WordPress decision/outbox slice — 2026-11-06

The first domain is Sheet conflict resolution. WordPress stores a normalized choice
against the exact conflict/source revision and writes its Railway command in the same
InnoDB transaction as the review event. The outbox command ID is deterministic for
that conflict revision, and a competing choice for the same revision is refused.
Railway checks `expected_revision` before applying a choice, so an old dashboard card
cannot overwrite a newer Sheet or offer state.

The existing Railway worker polls a scoped WordPress REST endpoint, claims due commands
with a lease/backoff in the WordPress database, applies them through the existing
conflict resolver, and posts a receipt. Transient failures stay queued with backoff;
stale or rejected commands become failed and remain visible in the audit trail. The
dashboard retains its immediate action and retry path, but recovery no longer depends
on an owner reopening the dashboard. Railway remains the Sheet-effect worker; this
does not move the Sheet, source messages, extraction queue, or other SQLite ledgers.

Before retiring Railway's conflict ledger, compare live WordPress decision/outbox rows
and Railway conflict state under normal actions, transient outages, duplicate delivery,
worker restart, and changed-source races. Supplier-price, reference, publication,
orders and inventory domains remain on their existing paths until their own shadow
comparisons and reversible migration receipts pass.

The production deployment `fbba4d46-1ca5-4f2d-9af2-ae490cf92f60` is `SUCCESS` from
commit `d296285620bb86a087a1b0e1bd68c9dff7b77cce`. The web and pipeline worker both
entered `RUNNING`; the worker logged a successful authenticated WordPress outbox poll
with zero due commands. `/health` returned HTTP 200 and `healthy`. The REST schema
probe had already created/verified the WordPress tables and found no pending commands.
This validates startup and the empty-queue path; an actual conflict delivery and
transient-outage replay remain to be exercised when a real conflict is available.
