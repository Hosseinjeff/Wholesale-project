# Site changes and preservation workflow

Updated: 2026-11-01, Asia/Tehran

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

Most recent plugin deployment: commit `4f605fa0617ba7e6464cb4f32e5a051b6ce88c61`
(2026-11-01). The reference-linked approval, status history and buyer-profile flow were
deployed from `main` with `python tools/deploy_wordpress_plugin.py --since
b4626edf118cc2fbbc4f8753d1bbce66ecf0724a`. The uploader verified all 14 changed plugin
files by read-back. It reported the documented cPanel `<meta charset>` insertion in
`includes/storefront-renderer.php`; removing that insertion yielded a byte-for-byte
source match. The owner snapshot page, Woo product controls, shop/cart profile selector
and order-line buyer units are included. No product/order records were changed.

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
