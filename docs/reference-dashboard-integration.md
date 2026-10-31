# WordPress dashboard integration

## Persian slash notation correction — 2026-10-31

The parser and Gemini prompt now treat ASCII `/` in Persian measurements as a decimal
separator: `1/2ل` is `1.2 لیتر`, and `1/5ل` is `1.5 لیتر`. Commit `47c4ee7` is deployed
to healthy Railway deployment `a2c53fc2-25f8-41f2-9204-b90ec196c196`; 54 focused tests
pass. A subsequent full refresh failed on page 1 with
`woocommerce_transport_read_timeout_page_1` after 189 seconds. Read-only probes of the
supplier homepage, WordPress JSON endpoint and Store API timed out. No candidate was
created. The previous complete candidate `2976f2004a643e5546dc242ec876607dad1fd03d2fb6ee7cd124881d30497380`
still carries the old `0.5 لیتر` interpretation for the `1/2ل` product, remains inactive,
and must not be activated. No reference projection was written; restage after the source
API is reachable.

## Previous successful full refresh before notation correction — 2026-10-31

Repeated owner refresh attempts had returned a generic transport failure. Commit
`a157ee5` lowers page size and concurrency for a complete retry after transport,
rate-limit or server errors, and reports a page-specific transport code if retries
still fail. Railway deployment `b7901971-078f-44f7-8ce5-46586d807e91` is healthy; the
WordPress browser client from the same commit was uploaded and read back successfully.
An owner-authorized retry staged snapshot
`2976f2004a643e5546dc242ec876607dad1fd03d2fb6ee7cd124881d30497380` with 3,919/3,919
products across 40/40 pages and verified coverage. The parser preserved both titles but
misread the slash in `1/2ل` as a fraction, producing `0.5 لیتر`. This snapshot remains
inactive and must not be activated. No `ReferencePrices` write was made.

## Source correction and initial API snapshot — 2026-10-30 (superseded checkpoint)

The user clarified that Rezvan reference products must come from the WooCommerce Store
API, not the official PDF. Railway deployment `f87efd11-381c-4195-b82e-ce26263b78be`
from commit `3d8a7d2` runs the API importer; the deployed MolaviShop owner-page PHP/JS
files were read back from that same commit. The live API returned 3,895 products in 39
pages; all have titles, SKUs, categories and positive IRR prices. Candidate
`4bf9d367c07dcf1af29e4fc61dfb1eddcb23cbbc4db3ffcdeb63fc987e80f7a9` is staged with
verified full coverage, converting IRR to toman and preserving each complete title.
It remains inactive pending owner review; refresh does not write to `ReferencePrices`.
Legacy PDF/Gemini candidates remain visible for history but cannot be reviewed or
activated.

## Historical deployment checkpoint — 2026-10-28 (PDF source superseded)

The earlier MolaviShop version was installed on the existing WordPress site. The private
bridge config is outside Git; the production server and persistent volume are live. The
PDF refresh staged two incomplete 10-row legacy candidates; they were never activated or
written to `ReferencePrices`. This old path is superseded by the user-confirmed API source.

**Historical outage (2026-10-29, Asia/Tehran):** the public WordPress entry point failed
before plugins load because `public_html/wp-config.php` is zero bytes. The Railway API
and staged candidate remain available, but Woo admin review cannot be reached until the
hosting provider restores the original config. The accessible cPanel account exposes no
backup feature or saved copy; database credentials are not present in the environment.

**Later live recheck (2026-10-29):** WordPress and the storefront now load publicly, so
the bootstrap outage above is historical and resolved. The available authenticated
session is administrator user 1; the project owner allowlist remains user 3, so the
owner-only Molavi operations page is not registered for user 1 and WordPress returns its
standard access-denied response at that time.

**Owner access correction (2026-10-30):** a fresh live Users-page check found two
Administrator accounts and no account 3. At the user's direction, the existing second
Administrator became the sole owner in protected WordPress configuration, with the
matching Railway `REFERENCE_OWNER_IDS` value. The WordPress config read-back matched;
Railway accepted the variable update and its deployment succeeded. Exact account
identifiers and private values are kept out of Git. The owner screen's bridge was repaired
later that day as described below.

**Reference bridge repair (2026-10-30, Asia/Tehran):** the user reported
`اتصال تازه‌سازی فهرست مرجع تنظیم نشده است.` A read-only cPanel check confirmed that
`includes/runtime-config.php` was absent and the protected `wp-config.php` did not define
the reference server URL or bridge token. The existing owner allowlist remained user 2
only. A new dedicated bridge token was set on Railway and the matching URL/token constants
were added to protected `wp-config.php`; secret values and the backup remain outside Git.

The Railway service was still running its `main` deployment at `2e32dea`, which did not
contain `/reference/snapshots`. It now tracks the pushed release branch
`molavisale-release-2026-10-28`; deployment `6062fc3d37a581f9666a24612288df63fd1da084`
completed successfully with the existing `/data` volume attached. A read-only authenticated
GET using actor `wp:2` returned HTTP 200 with two snapshots and refresh state `staged`. The
public WordPress homepage returned HTTP 200. No review, activation, refresh, or
`ReferencePrices` write was performed. The owner page still needs a visual check in the
user 2 browser session.

**Owner-page transport fix (2026-10-30):** the live WordPress host timed out when PHP
called both `/reference/snapshots` and Railway `/health` (`cURL error 28`), while a
public HTTPS control worked. Railway was healthy and received no request from that host.
The snapshots dashboard now reads and submits through its signed-in owner browser. PHP
signs a ten-minute, snapshots-only HMAC capability with the private bridge secret; the
secret itself never enters browser HTML. Railway validates the token, actor and owner
allowlist. CORS is limited to `https://www.molavisale.ir` and
`https://molavisale.ir`, only on `/reference/snapshots`, with no credential cookies.

Railway's production source is now `molavisale-release-2026-10-28` after a config
redeploy selected `main`. Final deployment `17f7f764-9199-4b81-8222-5e7a89cee594`
succeeded at source commit `cf252f1dcbe4d0caeb9a6da29029a21cb5b8b069`. The tracked
MolaviShop browser client and PHP signer from that commit were deployed and read back.
The live WordPress owner page emitted its short-lived token; its snapshots GET returned
HTTP 200 with two candidates, and the browser-origin preflight returned HTTP 204. With
the allowlist restored to user 2 only, actor `wp:2` returns HTTP 200 and `wp:1` returns
HTTP 401. The active snapshot is still unset, refresh state is `staged`, and no review,
refresh, activation or `ReferencePrices` write was made. A visual interaction check in
user 2's browser remains open.

**User 1 access and simplified review page (2026-10-30):** at the user's direction,
WordPress user 1 was added alongside user 2 in both protected WordPress configuration
and Railway's `REFERENCE_OWNER_IDS`. The private WordPress config was backed up under
`/tmp`, changed only for the owner list, and read back as `[1, 2]`. The deployed Railway
source remains `molavisale-release-2026-10-28`; deployment
`1ede0c85-287d-4ec7-a811-9566a4ed4822` succeeded at commit `e071d3b0263f38c97464316fc697d0b449a53ba7`.
That commit simplifies the Persian page with clear status labels, a compact price table,
sample counts, and confirmations before PDF retry or price activation. It hides internal
snapshot hashes, extraction model names, and owner IDs.

Read-only checks signed in as WordPress user 1 and loaded WooCommerce → بررسی قیمت‌های
مرجع with its deployed script. User 1's page token and a separately signed user 2 token
both returned HTTP 200 from `/reference/snapshots`; CORS preflight returned HTTP 204.
The API still lists two candidates, `active_snapshot_id` is unset, and refresh state is
`staged`. The Railway health route and public homepage returned HTTP 200. A synthetic DOM
render check confirmed the simplified labels and table; the connected browser extension
was unavailable for a screenshot-based visual review. No refresh, review, activation, or
`ReferencePrices` write was made.

Confirmed: owner decisions belong in the private WordPress dashboard, integrated with
current WordPress functionality/plugins. Not Telegram as the decision surface.

Inspected pinned repository `2e32dea`: `shop-wp/molavi-shop/molavi-shop.php` provides
WooCommerce pricing, order notifications and Elementor widgets. `includes/sync.php`
provides authenticated catalog/media synchronization. These are repository facts,
not an inventory of currently installed/active production plugin versions.

Extend the existing MolaviShop plugin with `includes/reference-decisions.php`, loaded
once after the existing includes. Do not install a duplicate storefront/plugin or
create a separate customer account/login system. The new native admin submenu is
WooCommerce → تصمیم‌های مولوی‌سل. Elementor remains for storefront widgets, and
WooCommerce remains the existing order/product administration surface. Native admin
cards/buttons inherit WordPress styles. The page is not public and is not cached.

Configuration contract (the secret values remain outside the repository):

- `MOLAVI_REFERENCE_SERVER_URL`: HTTPS server origin. This is a NEW explicit bridge
  setting, not evidence of a legacy REFERENCE_REFRESH_SERVER_URL service.
- `MOLAVI_REFERENCE_BRIDGE_TOKEN`: dedicated random bridge secret, at least 32
  characters, held in server secret storage and protected WordPress configuration. Do
  not reuse catalog/order tokens. Browser HTML never includes this secret; it may carry
  only a ten-minute HMAC capability scoped to the snapshots route.
- `MOLAVI_REFERENCE_OWNER_IDS`: array of actual integer WP user IDs approved by the
  business owner. A user must also hold `manage_woocommerce`; general shop managers
  are not automatically entitled to this business-owner decision. The earlier record
  for production owner ID `3` was stale. At the user's latest direction, WordPress users
  1 and 2 are both approved. The IDs remain in protected configuration and must stay
  aligned between WordPress and Railway.
- Server owner IDs use matching `wp:<ID>` identities. Empty/missing configuration denies
  access.

The server exposes the `DashboardAPI` through `/reference/decisions` and the snapshot
workflow through `/reference/snapshots`. Railway stores `REFERENCE_STORE_PATH`,
`REFERENCE_OWNER_IDS` and `REFERENCE_BRIDGE_TOKEN`; the plugin loads an optional
`includes/runtime-config.php`, while production currently defines its private endpoint and
secret in protected `wp-config.php`. Both reference and pipeline SQLite databases are on
Railway's persistent `/data` volume. Multi-instance ownership and reconciliation still
need an operational design.

GET lists durable unresolved events. POST sends event ID, expected snapshot ID and
choice only. The trusted WordPress backend asserts the logged-in actor using the
long-lived bridge token; TLS is mandatory. The snapshots API additionally accepts a
short-lived HMAC capability for direct owner-browser requests because the live PHP host
cannot route to Railway. That capability is scoped to this route, expires after ten
minutes, and is accepted only for a configured owner. CORS is limited to the two exact
WordPress origins; the long-lived bridge token is never sent to a browser. Server errors
are generic and responses use no-store.

Decision form submissions check capability, owner allowlist and an event-bound nonce.
Snapshot actions use the signed browser capability and send the expected active snapshot
or refresh timestamp. The server checks owner identity, snapshot scope and conflicting
changes. Activation is accepted only with a matching `ReferencePrices` write receipt.
No failed or unknown response is treated as accepted.

## Current verification and remaining integration

The latest local runtime passes 100 assertions in disposable real WordPress 7.1.3 +
WooCommerce 11.2.1: configured owner/capability access, denial of
another administrator, output escaping, native nonce rendering/rejection, missing
snapshot behavior, backend actor assertion, token exclusion, redirect prevention,
matching decision receipt and offline failure. HTTP server calls are mocked in the
WordPress runtime tests; server API behavior is separately tested in Python.
Full MolaviShop entry point loads. See reference-dashboard-runtime-evidence.txt.
No visual browser or Elementor/Wordfence compatibility pass is claimed.

For the 2026-10-30 browser-route change, the focused Python snapshots suite passed all
6 tests, including signed-token expiry, owner checks, preflight and restricted CORS.
The browser script passed Node syntax and a DOM-mock render check. The live owner page
returned HTTP 200 with the script and a short-lived token, and the token/origin request
returned the two staged snapshots. PHP CLI was unavailable in the workspace; the live
WordPress page parsed and served the deployed PHP. A visual interaction check in user 2's
browser remained open at that checkpoint. The later UI commit passed Node syntax and a
synthetic DOM render check; live WordPress user 1 and user 2 snapshot GETs each returned
HTTP 200 and the deployed JavaScript matched the committed file. No activation or other
state-changing snapshot action was submitted. A screenshot-based browser review remains
unverified because the browser extension was unavailable.

The deployed plugin also contains separate owner screens for supplier-price verification,
crash recovery, AI confirmations, reference item decisions, reference refresh retry and
snapshot review/activation. Real end-to-end transport now stages the live candidate.
Read-only decision history and
browser/Elementor/Wordfence layout remain open. Supplier price age uses the original
source timestamp or an owner-audited manual verification; a refresh/import timestamp
alone does not reset age.

## Item-level correction continuation — 2026-10-28

`/reference/item-reviews` lists only current offers tied to the active usable snapshot.
The private Woo page ranks reference candidates for the owner, records an explicit
reference and unit/package role or records no match while keeping the item unavailable.
The server rechecks the current offer, snapshot, selected reference, owner identity and
expected prior decision. Corrections are retained in the private reference SQLite
ledger. Offers remain unchanged; approved corrections rebuild the Products projection
and enqueue the serialized Woo publication path. Package prices are normalized to a
unit cost in Products using a verified pack count, while Offers keep the supplier's
raw amount and source record. A correction can be amended from the recent-decision
list; stale forms cannot overwrite a newer decision.

Weighted candidates use the recovered attribute weights as suggestions only; manual
owner choice remains required when the exact resolver cannot safely decide. Local code
includes category/history price-scale fallback, the owner AI confirmation state
machine, scheduled Woo API candidate staging, and owner-controlled activation and
`ReferencePrices` projection. The complete API candidate is staged and inactive.
Supplier-message Gemini is separate; Rezvan reference acquisition does not use it.
Live Sheets edits/projection and connected checkout remain unverified. Latest local
validation is 268 Python tests and 104 disposable WordPress 7.1.3/WooCommerce 11.2.1
assertions. Browser and active-plugin compatibility remain unverified.

## Read-only live inspection receipt — 2026-10-25

Authenticated wp-admin inspection succeeded using existing configured credentials.
WordPress reports 7.1.3. Active: MolaviShop 0.5.0, WooCommerce 11.2.1, Elementor
4.3.5, Elementor Pro 4.3.1, Wordfence 9.0.2, Google for WooCommerce 3.10.0,
Hello Plus 1.7.8, Abrha Payment 1.2.3 and Abrha Assistance 4.3.1. WP Rocket
3.23.5.1 is installed but inactive. Versions are reported by the live admin screen;
this is not execution compatibility validation of the draft. WooCommerce admin
menus are visible to the configured login. No plugin/settings/content changes were
made. Do not treat that login as approval to grant new decision permissions.

Git fetch succeeded with explicit network access; local review branch is based on
2e32dea. The module now reads the preserved GAS file directly for differential tests.
No duplicate legacy snapshot is required. PHP 8.3 syntax checks now pass for the plugin entry point and new admin include.
WordPress runtime/browser verification remains outstanding.
