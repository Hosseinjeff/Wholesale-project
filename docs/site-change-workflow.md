# Site changes and preservation workflow

Updated: 2026-11-03, Asia/Tehran

## Latest live deployment — 2026-11-03

WordPress plugin commit `c8161803f866d6c3b1557fc97cbefae53a1dc8bc` is deployed. The cPanel
uploader read back `includes/owner-workflows.js`, `includes/owner-workflows.css`, and
`includes/storefront-renderer.php`; the admin page serves byte-matching JavaScript and CSS.
The dashboard opens on the first non-empty priority queue, shows one product card at a time,
offers in-place filters for each type of work, and folds secondary checks for the same product.
After a decision, the card remains mounted while the data refreshes and a persistent result
message appears. Queue changes do not make another request.

`node --check` passed for the workflow script and its test, `node tests/owner_workbench.test.js`
passed, and `git diff --check` passed. The interaction test verifies one-card navigation,
queue filtering, collapsed secondary checks, asynchronous saves, persistent feedback, and no
page navigation. An authenticated read-only admin request returned HTTP 200. PHP CLI is not
installed in this workspace, so the PHP runtime test could not run. No product, order, or Sheet
data changed.

The live queue returned 26 extraction cases, 7 Sheet conflicts, and 109 supplier-price checks;
the reference and publication-hold queues were empty. The 124-product status snapshot is no
longer presented as a product list; the dashboard starts in the highest-priority non-empty
queue and keeps the rest behind explicit filters.

The storefront renderer source is deployed and read back, and the public page has 14 cards with
no stock/availability labels or known supplier names. The live HTML still has blank headings on
nine cards (IDs 55, 53, 51, 49, 47, 45, 43, 41, and 38), so the title fix is not yet verified at
runtime. WP Rocket is inactive, `wp-content` has no cache drop-ins, and cPanel reports that
Nginx caching is unavailable; no supported account-level PHP-FPM/OPcache refresh was exposed
through this cPanel account. A temporary PHP cache-diagnostic endpoint was rejected by
automatic review because it would add executable code to production and could remain if cleanup
failed; no endpoint was installed. Resolve this through a supported PHP-FPM/OPcache refresh.

## Latest live deployment — 2026-11-03, 19:18 UTC

WordPress plugin commit `a567b4b0b109e68a353c27bca30f3e79fc33702c` is live. cPanel
uploaded and read back `includes/operations-dashboard.php`, `includes/owner-browser-api.php`,
`includes/owner-review-store.php`, `includes/owner-workflows.css`, and
`includes/owner-workflows.js` with no host normalization. The owner operations page now
loads extraction, reference matching, supplier-price freshness, Sheet conflicts, catalog
status, and publication holds into product-centered cards. Shared batch actions appear once;
global and order work remains in collapsed sections. Review context and receipts stay in the
WordPress database, while card actions use asynchronous requests.

`node --check` passed for the workflow script and its test, `node tests/owner_workbench.test.js`
passed, and `git diff --check` passed. The admin interaction test verifies product grouping,
scoped route tokens, local review context, and asynchronous supplier-price verification.
The PHP runtime test could not run because PHP is not installed; Flask-backed route tests
could not run because Flask is not installed. An authenticated read-only request to
`molavi-operations` returned HTTP 200 with the workbench mount and scoped API configuration;
the JS and CSS assets also returned HTTP 200. An authenticated visual click-through of
`wp-admin` was unavailable, so the dashboard has interaction-test and live page/asset checks,
not a live screenshot. No product, order, or Sheet data changed. This commit does not change
Railway.

## Previous live deployment — 2026-11-03, 16:48 UTC

Commit `dc0e3d4ffdc0aa17581bbabca7143cd8108e74bf` is live in the WordPress plugin and
Railway backend. cPanel uploaded and read back `includes/admin-ajax-forms.js` and
`includes/owner-browser-api.php` with no host normalization. The authenticated
`molavi-operations` page returned HTTP 200, included the AJAX form handler, and the
script asset returned HTTP 200. The handler submits owner-only `admin-post.php` forms
with `fetch`, refreshes only the current admin content after a successful save, and
shows inline errors without navigating away. Existing route-driven owner workflows
continue to use their fetch interactions.

Railway deployment `6dc4c738-1fbf-4ae9-8636-bd44fe761db3` succeeded from `main` at the
same commit. The service has one running replica, its 500 MB `/data` volume is attached,
both production services are online with no issues or pending work, and `/health` returned
HTTP 200 with `healthy`. The prompt and title-scoped evidence update passed the three-post
live smoke check recorded in [`ai-extraction-validation.md`](ai-extraction-validation.md).

## Storefront layout release — 2026-11-03

WordPress plugin commit `ad211aed122ee23ec84847534e512e9dd2670dc4` is deployed and verified
by cPanel read-back. The shop template no longer loads the theme header/footer shell or its
separator markup. It calls Elementor Pro Theme Builder locations first; the live shop has
no assigned Elementor header/footer locations, so it renders the plugin header fallback and
no footer. The shop has no CSS hide rule for Elementor or theme chrome. Supplier names are
removed from product cards, public excerpts and short descriptions. The browser check returned
14 cards, one visible header, two buyer selectors, no legacy theme markup, and no supplier
mentions on the checked product detail. Switching to store-owner mode updated both selectors;
only the product with an invalid order step prompted for a fix. At 390px, the page had no
horizontal overflow and the cart drawer showed its buyer selector. The standard `/cart/` route
uses the WooCommerce block cart, with no classic “Update cart” button. Desktop, mobile and
product-detail screenshots were captured under `/tmp/molavi-*-final.png`.

The preceding WordPress plugin deployment at `d08158589081fb6f026845d0d6984c0942c307ca`
also verified the new owner-card price presentation and direct candidate links. The Python
backend changes are live from `main` commit `0492f62` on Railway deployment
`fcf76ec5-e566-4501-9a82-61df3c0beaba`. The five unique supplier posts in the validation
exports produced seven products with no review holds. The explicit flavor list expanded
into three product records; a five-flavor count without flavor names remained one product.
Unit and package prices were preserved separately. See `project-handoff.md` for the
validation details. Railway is online with one running replica and no pending work;
`/health` returned HTTP 200 with `healthy`.

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

Most recent WordPress plugin deployment: commit
`a567b4b0b109e68a353c27bca30f3e79fc33702c` (2026-11-03). The uploader changed and read
back five owner-dashboard files with no host normalization. The preceding deployment at
`dc0e3d4ffdc0aa17581bbabca7143cd8108e74bf` (2026-11-03) uploaded `includes/admin-ajax-forms.js`
and `includes/owner-browser-api.php`. The earlier storefront deployment at
`ad211aed122ee23ec84847534e512e9dd2670dc4` changed and verified
`includes/site-chrome.php`, `shop-template.php`, and `storefront.css`.
cPanel inserted its documented charset marker after `<head>` in the standalone template and
after `<header>` in the site-chrome partial; both exact changes were normalized and verified. The preceding
deployment at `37fe2a73eaf034393f6d5dd15a7aff40e67f4fe3` uploaded and verified `molavi-shop.php`.
The earlier deployment at
`1e7f9d8734387ead1119bc6b61886b185b26b9ce` explicitly renders the shop header and removes
the legacy theme chrome. The preceding storefront cleanup commit `3be4525f9394115e0533825c0c523bc524e8081a`
uploaded seven plugin files for buyer selectors, supplier cleanup, cart feedback and stable
card updates. The prior plugin deployment at
`d08158589081fb6f026845d0d6984c0942c307ca` uploaded and verified the owner workflow JS/CSS,
pricing/storefront renderer, shop JS and storefront CSS. Its cards show distinct supplier
unit/carton prices and clickable reference suggestions with comparison details when the
backend supplies them. Focused Python tests and JavaScript syntax checks passed.

Railway read-back shows the production `Wholesale-project` service following
`Hosseinjeff/Wholesale-project@main`, with backend commit `0492f62` deployed on
`fcf76ec5-e566-4501-9a82-61df3c0beaba`. The service retains its 500 MB `/data` volume.
Five unique supplier posts were checked through Gemini after the prompt/parser update;
the normalized result contains seven products and no review holds. Local extraction,
matching and reference-review tests pass (54 total).

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
`<meta charset="utf-8">` after the first opening `<head>` or `<header>` tag in PHP
templates. The uploader accepts only that exact insertion when removing it yields a
byte-for-byte match with the committed file, and reports any affected paths. It
deliberately excludes
`shop-wp/molavi-shop/includes/runtime-config.php` and does not package or replace
the plugin directory.

After a successful deploy, record the deployed commit here or in the relevant
release note and use it as `--since` for the next deployment.

Earlier plugin deployment: commit `a80e20865ea4f196956f41a4d9743f6f74c992f6`
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
