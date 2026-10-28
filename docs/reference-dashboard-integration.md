# WordPress dashboard integration

## Deployment checkpoint — 2026-10-28

The updated MolaviShop plugin files and owner bridge configuration are installed on
the existing WordPress site. The private bridge config is stored at
`includes/runtime-config.php`, outside Git; it binds the HTTPS Railway service and
owner user 3. The production server and persistent volume are live. After the user
replaced the Gemini key, the live Rezvan PDF refresh staged a 10-row candidate; the Woo
admin exposes an 8-row preview, followed by owner review and explicit activation. The
active snapshot remains null and no `ReferencePrices` write has occurred. The deployed
preview file matches the tested local file byte-for-byte. The plugin's remote admin page
and active-plugin/browser interactions still need a visual live UI check.

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
- `MOLAVI_REFERENCE_BRIDGE_TOKEN`: dedicated random server-to-server secret, at least
  32 characters, held in server secret storage and protected WordPress configuration.
  Do not reuse catalog/order tokens. Browser HTML never includes this secret.
- `MOLAVI_REFERENCE_OWNER_IDS`: array of actual integer WP user IDs approved by the
  business owner. A user must also hold `manage_woocommerce`; general shop managers
  are not automatically entitled to this business-owner decision. Production owner
  ID `3` was confirmed through the authenticated WordPress user endpoint for the current
  administrator; do not add other IDs without authorization.
- Server owner IDs use matching `wp:<ID>` identities. Empty/missing configuration denies
  access.

The server exposes the `DashboardAPI` through `/reference/decisions` and the snapshot
workflow through `/reference/snapshots`. Railway stores `REFERENCE_STORE_PATH`,
`REFERENCE_OWNER_IDS` and `REFERENCE_BRIDGE_TOKEN`; the existing plugin reads its
private endpoint and token from `includes/runtime-config.php`. Both reference and
pipeline SQLite databases are on Railway's persistent `/data` volume. Multi-instance
ownership and reconciliation still need an operational design.

GET lists durable unresolved events. POST sends event ID, expected snapshot ID and
choice only. The actor is derived from the logged-in WordPress user on the backend;
the trusted WP backend asserts it to the server using authenticated transport. The
API must NOT be exposed directly to browsers with the bridge token. TLS is mandatory.
Do not add permissive CORS. Server errors are generic and responses use no-store.

WP form submission checks capability, owner allowlist and an event-bound nonce. The
server checks dedicated bridge authentication, owner identity, event/snapshot scope
and conflicting changes. Exact retries return the same acceptance. A failed/unknown
response never marks a choice accepted. A missing last-successful snapshot hides the
continue button and is also rejected by the server if submitted directly.

## Current verification and remaining integration

The latest local runtime passes 100 assertions in disposable real WordPress 7.1.3 +
WooCommerce 11.2.1: configured owner/capability access, denial of
another administrator, output escaping, native nonce rendering/rejection, missing
snapshot behavior, backend actor assertion, token exclusion, redirect prevention,
matching decision receipt and offline failure. HTTP server calls are mocked in the
WordPress runtime tests; server API behavior is separately tested in Python.
Full MolaviShop entry point loads. See reference-dashboard-runtime-evidence.txt.
No visual browser or Elementor/Wordfence compatibility pass is claimed.

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
now includes category/history price-scale fallback, the owner AI confirmation state
machine, scheduled PDF/Gemini candidate staging, and owner-controlled activation and
`ReferencePrices` projection. The owner bridge and storage are deployed; live Gemini
extraction succeeds, while live Sheets edits/projection and connected checkout remain
unverified. Latest local validation is 246 Python tests and 100 disposable
WordPress 7.1.3/WooCommerce 11.2.1 assertions. Browser and active-plugin compatibility
remain unverified.

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
