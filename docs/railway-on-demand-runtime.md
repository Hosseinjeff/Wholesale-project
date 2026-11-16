# WordPress-hosted scheduling and Railway idle policy

2026-11-16, Asia/Tehran. The owner explicitly authorized resource fixes on both
production and Staging. The free-plan constraint is an implementation requirement:
Railway must not poll external services while idle, and read/health requests must not
launch a worker. WordPress owns the clocks; Railway executes runnable Python work.

Before this change, both services had Railway sleep enabled but polled Sheets and the
WordPress outbox every minute. Recent memory was approximately 129 MB production and
137–149 MB Staging, plus a separate approximately 68 MB FollowUP-bot. Seven-day peak
samples reached approximately 301/307 MB. Disk metrics were approximately 408/500 MB
production and 87/100 MB Staging; these are distinct from RAM. Do not call a memory
limit reduction a saving or delete live data to hide the storage reading.

## Runtime contract

`PIPELINE_RUNTIME_MODE=on_demand` activates the bounded worker. Default continuous
mode remains available for rollback until the WordPress runner is verified. Telegram
intake commits to the existing SQLite queue before acknowledgement; owner writes wake
committed work after the response. Existing leases, retries, receipts and held/review
states remain authoritative. The worker drains runnable jobs, waits only for imminent
retries, records its next durable due time in WordPress and stops. Health/GET reads do
not start workers. A scoped owner endpoint `/pipeline/runtime` admits only four
maintenance types; maintenance reuses the existing leased job table.

The independent runtime MU-plugin is tracked in
`shop-wp/molavi-shop/runtime-host/molavi-runtime-host.php`. Deploy it with the normal
verified uploader's `--component runtime`; it never replaces an existing shop-plugin
file. This is important because production and Staging have different frontend versions.
The plugin is disabled until explicitly configured and exposes only scoped authenticated
REST endpoints. Its callback token is derived from the existing protected bridge token.
No secret is stored in Git. The worker completion option is separate from scheduler
options so a cron request cannot overwrite an early completion receipt.

A real cPanel cron invokes only `molavi_runtime_tick`, with a PHP file lock and a
WordPress timestamp proving CLI execution. It does not run unrelated overdue Woo jobs.
Ticks are local WordPress checks. Railway wakes for due retries, due WP-owned outbox
commands, hourly Sheet change observation, daily reference refresh through the canonical
reference route, and daily housekeeping. Empty ticks make no Railway request. Existing
reference activation/source authority and product/pricing rules are preserved.

The container runs Gunicorn directly, dropping worker privileges to the existing app
account. The unused Supervisor process/dependency is removed from the execution path.
One HTTP worker and one short-lived inline job thread share the existing volume.

## Checks and rollout

The focused Python tests cover idle/held queues, retry/intake wake times, maintenance
idempotence/leases, scoped callbacks, owner authorization, no worker on health/GET,
no periodic network activity while idle and no idle-worker respawn. The existing
pipeline/outbox tests remain relevant. The synthetic PHP suite checks scoped auth,
disabled/no-due ticks, typed configuration, due-only work, stale callbacks and the
worker-completes-before-wake-response race.

Install the MU-plugin, verify real cron and callback authorization on each site, then
enable the host and Railway mode. Read back each deployment's terminal state, endpoint
version, worker/storage report and resource metrics. After verification, stop HTTP
probes and observe sleeping via Railway's control-plane status/metrics; repeated health
checks would themselves wake the service. Preserve rollback and storage backups before
any later data compaction or authority cutover.

## Scheduler isolation and Telegram UX

The Staging safety MU-plugin blocks external HTTP by default. The runtime host uses
a temporary exception only for HTTPS POST to the exact staging Railway host and
`/pipeline/runtime`; production targets, other paths/methods and unrelated errors
remain blocked. Focused PHP cases cover this exception. The enabled host resets its
retry throttle after an explicit configuration change.

The Telegram button workflow in `bot-button-workflow.md` adds no polling or resident
worker. Reports are read-on-click, price choices are prompt-bound signed callbacks,
and corrections require only a numeric reply.

A broader legacy catalog suite was also examined with deterministic extraction
mocked: 40/41 cases pass; its old `Batch ID == sheet` expectation fails in unchanged
`catalog/sync.py`. An initial run inherited the workspace AI configuration and was
stopped; verification uses mocked extraction rather than live model requests.
