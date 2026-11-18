# MolaviSale continuity

Start with the [final WordPress cutover](docs/native-wordpress-workflows.md#final-live-cutover)
for the deployed state on live and Staging. WordPress owns business state and workflows.
Railway runs the on-demand bot executor with no mounted business volume. Production's
24-table transfer was verified before the switch. Its old inactive WordPress candidates
and the detached Railway volumes were retained for recovery. Do not point an executor
at those candidates or the removed local stores.

Read [the project handoff](docs/project-handoff.md) for the broader history.
The [staging owner fetch incident](docs/session-handoff-staging-owner-fetch.md) is historical.

The user-provided GAS export is preserved in `legacy-gas/recovered-2026-10-25/`;
`legacy-gas/README.md` explains its authority and limits. The root
`google_apps_script.js` is an older implementation, not the recovered snapshot.

Respect the user's request to conserve usage: search the inventory first, read only
relevant source ranges, keep tool output small, and reuse existing audit evidence
for unchanged code. Do not reload the full archive or rerun broad checks without a
specific need. Do not ask for the already-preserved export again.

Source recovery is not migration completion. Keep the original export unchanged;
track later exports separately. Active triggers/deployments and the external refresh
server remain unverified. Preserve the mockup-first scope and documented business
decisions; do not silently choose a different inventory or pricing authority.

## Railway free-plan resource baseline

The owner requires minimum Railway RAM and idle sleep on both live and Staging.
Use WordPress for schedules, due-work checks and wake bookkeeping. Railway is an
on-demand Python executor: health/GET reads must not launch workers, and idle loops
must not poll Sheets, WordPress or reference APIs. Keep one HTTP worker, drain leased
work, persist the next due time to WordPress, and release idle memory. Do not restore
an always-running worker, Supervisor, keepalive monitor or periodic outbound poll.
Preserve the durable queue/leases and prove real WordPress cron before changing a
runtime's execution mode. See `docs/railway-on-demand-runtime.md`; verify resource
measurements and actual sleep through control-plane reads, not HTTP probes that wake
it. Keep storefront/quote work in WordPress. Code releases are scoped by environment
and pinned to reviewed backend commits; frontend/docs commits must not redeploy it.
Keep bot workflows, extraction and source media on Railway. WordPress owns the inputs,
results and business actions. Legacy Railway routes can relay old callers to WordPress.
Preserve the newer Staging text-only bot release and its model routing when changing pins.
Keep verified receipts and one writer before removing old state. Background threads
must use `catalog.memory.start_background` so idle heap reclamation cannot interrupt
them. Health probes must neither arm reset loops nor postpone pending reclamation.

## Source control and live WordPress changes

Target Staging for development, validation, and deployments by default. Use Railway's
Staging environment and `staging.molavisale.ir`; use production only when the user
explicitly names production or the live site.

Treat tracked files in `shop-wp/molavi-shop/` as the source of truth for the live
store plugin. Make edits here, commit them, and push them to the configured Git
upstream before deploying. Use `tools/deploy_wordpress_plugin.py` for live plugin
files; it refuses an uncommitted or unpushed tree, keeps a local pre-deploy copy,
and never deploys `includes/runtime-config.php`.

Keep WordPress bridge constants in protected `wp-config.php` or the excluded private
`includes/runtime-config.php`; never commit either file or copy secret values into docs.

The checked-in `.githooks/post-commit` pushes each commit to its configured upstream
when that hook path is installed. After cloning, run `tools/install_git_hooks.sh`.
If a push fails, the commit is still local and must be pushed before deployment.

WordPress database content, products, orders, and customer data are not source
files and must not be added to Git. Export or back up database changes through the
documented WordPress backup process; never commit secrets or customer/order data.
See `docs/site-change-workflow.md` for the deployment and preservation steps.
