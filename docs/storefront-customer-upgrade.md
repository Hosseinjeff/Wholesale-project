# Customer storefront upgrade

Updated 2026-11-16, Asia/Tehran. Implements the non-data parts of the storefront review.
Product titles, categories, attributes, images, pack metadata and pricing bands are
not corrected by this release. Those remain product-pipeline responsibilities.

## Preserved baseline

Staging's deployed frontend differed from Git. Its six changed frontend files were
read, preserved under `/tmp/molavi-storefront-upgrade-baseline`, reviewed and reconciled
in commit `730f4cf` before implementation. The reconciliation preserves the existing
compact toolbar, buyer picker, quantity repair behavior, price controls and CSS.
It introduces no business-policy change. Host-inserted charset markers were normalized.

## Customer journey

`customer-template.php` uses one Molavi header/footer for product, product taxonomy,
cart, checkout, account and native order endpoints. WooCommerce retains its templates,
forms, validation, nonces and payment/order handlers. The dedicated catalog template
remains in place. Default block-theme starter navigation is absent from this shell.
Customer styling is in `customer.css`, using the project's existing fonts and palette.

Guest checkout is enabled by the existing confirmed policy; mobile is required and
email/account are optional. No gateway is selected or activated. Gateway-specific
requirements and actual payment settlement must be verified with the configured
provider. Native order details/reordering remain authorized by WooCommerce; no public
lookup by phone or new parallel order ledger is introduced. The native order-received
page explains verified versus pending payment and retaining the secure receipt link.

The generic maximum-discount carousel is replaced by a short purchase explanation.
The buyer choice, actual pricing engine and maximum/current discount values remain.
Add controls name the current quantity. Catalog cards omit the additional calculated
minimum total; product-detail quantity and price explanations remain. Grid and list
cards show complete wrapping titles, prioritize the current price and keep quantity
controls below. The short top guide responds to an empty cart, active discount or
invalid quantities. Buyer information explains profile effects and quantity repair.

The header heart opens one native dialog on catalog, product, cart, checkout and
account pages. Its keyboard-accessible tabs show favorite products and saved carts;
these tools are outside the cart drawer. Products have current public details and
shared quantity controls. Saved carts show expandable item previews, direct add/new
active-cart actions, inline optional renaming and deletion. Names are automatic.
Saving all favorites as a new saved cart performs no Woo cart write. Saving the active
cart does not add its products to individual favorites. Existing browser records are
preserved: product IDs in `molavi-wishlist-v1`, and at most ten ID/quantity/name lists
in `molavi-shopping-lists-v1`. No old prices or contact details are stored.

The compact cart remains at the lower left. Its count sits inside the icon at the
upper right, is absent for an empty cart and caps visually at 99+. Confirmed cart
changes show a 4.2-second current-discount/savings summary before retracting.

Restoring a list validates the nonce, item count/types, current simple-product
eligibility and current approved quantity increments. It merges with the active cart,
reports skipped/adjusted lines and returns freshly calculated prices and public facts.
An idempotent request token in the Woo session prevents an uncertain network response
from doubling quantities when retried. It does not submit an order or charge a payment.
Creating a new active cart from a saved cart uses the same endpoint in explicit
replacement mode. It first saves the old cart locally, then uses current product eligibility and
minimum quantities. Unavailable-only requests preserve the old cart; a failed write
restores the original contents. Request receipts include the operation mode and make
replacement retries idempotent. No second cart ledger is introduced. The heart library reads native cart form quantities and waits for Woo updates.
Library quantity/restore actions re-read native cart HTML with a GET, preserving
the open heart panel and avoiding a POST of old form quantities.
Checkout is blocked while a quantity/profile/list update is unresolved.

## Delivery and pricing

The owner subsequently requested a new visual direction, focused on layout, spacing
and typography. `customer.css` now defines warm-white/ink/blue customer styling,
consistent content widths, quieter controls, complete titles and aligned card prices.
Header navigation is simplified; product galleries are contained on phones; account
forms are aligned; empty carts offer shop and heart entry points. Native Woo forms,
validation, checkout and payment handlers remain. The owner dashboard is separate.

The existing supplier prices, price bands, household/shop caps, tuna-based discount
threshold and quantity policy remain authoritative. Delivery estimates and rates are absent from both catalog and native carts; the
native cart defers shipping calculation. The existing checkout zones and methods
still determine shipping from the address. The approved delivery policy remains
available in shopping help and quote metadata; it never uses the discount threshold
as the delivery threshold. No shipping policy, zone or instance is changed.

## Catalog delivery

`includes/storefront-catalog.php` maintains a 60-second static public index and renders
at most 24 product tiles per request. Search normalizes Arabic/Persian character and
digit variants over existing names/packaging/category values. Categories are read as-is.
Current-price sorting uses the server quote. The same endpoint's `format=collection`
returns at most 24 public facts plus the current quote without card HTML. The heart
library reads pages lazily, cancels obsolete requests and rejects older cart/profile
revisions. It keeps local selections after failures and offers a visible retry. Each returned product's active eligibility
is checked again after the index cache. The client debounces filters, cancels older
requests and rejects a catalog response from an obsolete cart/profile revision.

Initial browser product facts are limited to the rendered page, current cart and
current single product. Further pages return only their public facts and HTML.
Supplier costs and pricing floors remain server-side. The existing cached pricing map
and cart quote remain responsible for complete-catalog price calculations.

## Verification

`tests/customer_storefront_runtime.php` runs against synthetic Woo-compatible objects
and the actual price functions. It checks first/last pages, Persian search, quote sorting,
delivery threshold boundaries, optional email/required mobile, skipped unavailable
products, current quantity steps, public-only restored facts, idempotent retries and
validation before mutations. The 20 assertions also cover replacement rollback,
current minimum quantities, mode-bound retries, cart-only shipping deferral and a
bounded collection read without cart mutation.
It can generate local browser fixtures when
`MOLAVI_FIXTURE_DIR` is set. Run with PHP 8 or the disposable PHP Wasm CLI.

`tests/storefront_interactions.browser.cjs` runs the generated fixtures in Chromium
with intercepted mock APIs. The 40 checks cover bounded tiles, read-only page/heart
loading, current quantity labels, pending-save checkout blocking, no cart delivery or
card total, shared off-page favorite facts/controls, saving favorites without a cart
write, automatic names, inline rename, save-current behavior, merge/replacement with
backup, complete titles in both layouts, 320/390px fit, correctly anchored cart count,
peek/retraction, accessible modal/tab navigation, read failure/retry, native cart
quantity capture/update blocking, help and pagination. Screenshots stay in `/tmp`.

Connected release checks must use an isolated anonymous Staging cart, inspect native
product/cart/account/checkout rendering, confirm the fresh quote and restore endpoint,
and empty that test cart afterwards. No order, payment, refund, supplier procurement
or customer notification is needed for these checks. Paid callbacks, real fulfillment
status and customer shortage/refund decisions need their existing connected workflow
and provider; UI rendering is not evidence that these business actions completed.
