# Site changes and preservation workflow

Updated: 2026-11-02, Asia/Tehran

## Source of truth

- WordPress plugin source lives in `shop-wp/molavi-shop/` in this repository.
- The user directed new changes to use `main` from 2026-11-01. The older
  `molavisale-release-2026-10-28` branch is historical; deploy new work from `main`.
- Do not edit live plugin files in WordPress or cPanel. Change the repository copy,
  commit it, push the commit, and deploy from that pushed revision.
- The `.githooks/post-commit` hook pushes every commit to the branch's configured
  upstream. Install it for each clone with `tools/install_git_hooks.sh`. If a push
  fails, the commit remains local and the hook reports that it needs a retry.

## Deploying plugin code

Most recent WordPress plugin deployment: commit 78b3188ed0b377bf9a573ccec158cee516f7d334
(2026-11-02). The uploader changed and read back the affected PHP, JavaScript, and CSS
files. The plugin includes the owner-review interface, WordPress review-table migration,
and audit-write path; live table creation and writes still need an authenticated admin
check.

The companion Railway API is now live in production. The existing service is connected
to Hosseinjeff/Wholesale-project on main and automatically deploys pushes. Deployment
1ff80683-d0cf-4b4c-938d-a08426be5d8b succeeded from commit
4020da234f62e4483eddf169c9bf3fd062335878. Production is online with one running replica,
no warnings or pending work, and /health reports healthy. The public storefront fetched
successfully; the WordPress admin click-through and review-table writes remain unverified
until the browser profile has a saved sign-in.

The cPanel uploader is `tools/deploy_wordpress_plugin.py`. It accepts a Git ref
before the plugin changes, and only deploys tracked PHP, JavaScript and CSS files
under `shop-wp/molavi-shop/` that changed since that ref.

```sh
python tools/deploy_wordpress_plugin.py --since <last-deployed-commit>
```

The script requires a clean working tree and an upstream that points at the same
commit as `HEAD`. It reads cPanel credentials from the existing
`MOLAVISALE_CPANEL_URL`, `MOLAVISALE_CPANEL_USERNAME` and
`MOLAVISALE_CPANEL_PASSWORD` environment variables; it never prints them. It uses
the HTTPS cPanel UAPI through the configured proxy, backs up changed remote files
under `/tmp`, writes only the changed tracked source files, and reads each file
back to compare it with Git. The current cPanel file editor inserts one
`<meta charset="utf-8">` after the first opening `<header>` tag in PHP templates.
The uploader accepts only that exact insertion when removing it yields a
byte-for-byte match with the committed file, and reports any affected paths. It
deliberately excludes
`shop-wp/molavi-shop/includes/runtime-config.php` and does not package or replace
the plugin directory.

After a successful deploy, record the deployed commit here or in the relevant
release note and use it as `--since` for the next deployment.

Most recent plugin deployment: commit `a80e20865ea4f196956f41a4d9743f6f74c992f6`
(2026-11-02). `python tools/deploy_wordpress_plugin.py --since
3a5e9e0af76be33fc009a193768522e8130b3e18` uploaded `includes/owner-workflows.js`
and verified its read-back; no host normalization was reported. The Sheet conflict
screen now names Railway as the source of the default computed value, and the alternate
action explicitly confirms a manual Sheet edit before queueing WooCommerce publication.
JavaScript syntax and whitespace checks passed. No product, order or Sheet data changed.
The WordPress runtime test could not start because Docker socket access is blocked; an
authenticated visual admin click-through was not available.

The previous plugin deployment was commit `3a5e9e0af76be33fc009a193768522e8130b3e18`
(2026-11-02). `python tools/deploy_wordpress_plugin.py --since
53531d00632a070b8fe505e890a28d681fed945c` uploaded and verified the read-back of
`includes/operations-dashboard.php`, `includes/owner-browser-api.php`,
`includes/owner-workflows.css`, and `includes/owner-workflows.js`; no host normalization
was reported. The owner landing screen now puts the highest-priority open queue first,
shows counts on workflow tabs, refreshes after a review decision, and keeps technical
queue details collapsed. No product, order, or review-decision data changed. JavaScript
syntax and whitespace checks passed. The WordPress runtime test could not start because
the workspace cannot access the Docker socket; Python route tests could not start because
Flask is not installed in this workspace. An authenticated visual admin click-through was
not available from this workspace.

The previous plugin deployment was commit `53531d00632a070b8fe505e890a28d681fed945c`
(2026-11-02). `python tools/deploy_wordpress_plugin.py --since
4f605fa0617ba7e6464cb4f32e5a051b6ce88c61` uploaded six changed plugin files and verified
their read-back. The uploader reported the documented cPanel `<meta charset>` insertion
in `includes/storefront-renderer.php`; removing that insertion yielded a byte-for-byte
source match. The updated owner dashboard, product trace details, and storefront price
data handling were included.

Railway deployment `2265d265-34ed-4762-a805-dcdc3eec06b5` reached `SUCCESS` from
`main` commit `53531d00632a070b8fe505e890a28d681fed945c`. `/health` returned `healthy`.
The public `/api/shop` response contained one product and no supplier channel, price-band
floor/ceiling, or margin-policy fields. The public Woo Store API still returned one
purchasable, in-stock product. An unauthenticated GET to `/pipeline/reconciliation`
returned HTTP 401; the owner-authenticated report and live admin click-through were not
exercised. No production product, order, review decision, or payment record was changed.

Python compilation and JavaScript syntax checks passed. PHP CLI is unavailable in this
workspace, and no automated tests or extraction-accuracy evaluation were run. The latter
needs a private owner-labelled supplier-message corpus; see
[`ai-extraction-validation.md`](ai-extraction-validation.md).

Railway production service `Wholesale-project` follows `main`. Deployment
`226ab34b-7b10-4512-b275-8c6105b59a5b` reached `SUCCESS` from commit
`4f605fa0617ba7e6464cb4f32e5a051b6ce88c61`; `/health` returned HTTP 200 and `healthy`.
The 500 MB `/data` volume remained attached. Python and JavaScript syntax checks passed;
PHP CLI was unavailable in the workspace, and no automated tests or live browser
click-through were run.

The previous plugin deployment was commit `b4626edf118cc2fbbc4f8753d1bbce66ecf0724a`
(2026-11-01). The tabbed MolaviSale workspace was deployed from `main` with
`python tools/deploy_wordpress_plugin.py --since
0a3e7f27c12f419d175645592166913e2c9f8db1`. The uploader changed and verified by read-back
`includes/operations-dashboard.php`, `includes/owner-workflows.css`, and
`includes/owner-workflows.js`; it reported no host normalization. No product/order records
or Railway service were changed. The previous plugin deployment was commit
`0a3e7f27c12f419d175645592166913e2c9f8db1` (2026-11-01), which integrated product and
order context into WooCommerce and verified `includes/owner-workflows.js`,
`includes/pipeline-holds.php`, `includes/woocommerce-admin.php`, and `molavi-shop.php`.
The previous plugin deployment was commit `3ed092cbf6c2f2ac669b8532837c317d3429d72c`
(2026-11-01), where bulk
message/case dismissal, optional dismissal notes, and visible reference suggestions in
`includes/owner-workflows.js` and `includes/owner-workflows.css` were uploaded and read back
successfully with `python tools/deploy_wordpress_plugin.py --since
6448f09e0f5579f7094f5a097b3eafacc90144e0`. The previous plugin deployment was commit
`6448f09e0f5579f7094f5a097b3eafacc90144e0` (2026-11-01), which added the owner
extraction panel's AI-only configuration message and stale-backend detection from base
`66b90019860fe3a7309802c56235c177359dd720`. The prior deployment, commit
`66b90019860fe3a7309802c56235c177359dd720`, uploaded the owner re-extraction controls
from base `4c5d83852e4eb6ec2a627f433ed9286d1a8aa637`. Use the deployed plugin commit as
the next deployment's `--since` value.

## WordPress data

Git contains code and safe project documentation. Products, pages edited in
WordPress, customer records, orders, uploaded media and runtime credentials live
in the WordPress database or private storage. They are not automatically captured
by Git and must not be copied into this repository. Use host/database backups for
those records. When changing customer-facing page content in WordPress, export the
safe content or recreate the change in a tracked template before calling that
change version-controlled.

Never commit `wp-config.php`, `runtime-config.php`, service-account keys, supplier
source contents, customer data, order data, database files or `/tmp` backups.
