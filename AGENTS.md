# MolaviSale continuity

For the business workflow and migration audit, start with `docs/project-handoff.md`.
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

## Source control and live WordPress changes

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
