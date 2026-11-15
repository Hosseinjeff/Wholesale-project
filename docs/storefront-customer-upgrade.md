# Customer storefront upgrade

2026-11-15, Asia/Tehran. Implements the non-data parts of the storefront review.
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
Add controls name the current quantity; minimum-selection totals update with server
prices. Product-detail quantity and price summaries follow the selected buyer profile.
Repeated-purchase lists are collapsed until requested. Lists store only product IDs,
quantities and a user-supplied label locally in the browser, with at most ten lists.
They never store old prices or customer contact details.

Restoring a list validates the nonce, item count/types, current simple-product
eligibility and current approved quantity increments. It merges with the active cart,
reports skipped/adjusted lines and returns freshly calculated prices and public facts.
An idempotent request token in the Woo session prevents an uncertain network response
from doubling quantities when retried. It does not submit an order or charge a payment.
Checkout is blocked while a quantity/profile/list update is unresolved.

## Delivery and pricing

The existing supplier prices, price bands, household/shop caps, tuna-based discount
threshold and quantity policy remain authoritative. A separate delivery preview reads
the configured native Molavi shipping method where unambiguous, or the agreed initial
Tehran fee/threshold as an estimate. It labels the final delivery rate as address-dependent.
The preview uses discounted goods value and never substitutes the discount-unlock
threshold for the delivery threshold. Actual checkout shipping continues through the
existing Woo shipping zones and method. No zone or shipping instance is silently added.

## Catalog delivery

`includes/storefront-catalog.php` maintains a 60-second static public index and renders
at most 24 product tiles per request. Search normalizes Arabic/Persian character and
digit variants over existing names/packaging/category values. Categories are read as-is.
Current-price sorting uses the server quote. Each returned product's active eligibility
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
validation before mutations. It can generate local browser fixtures when
`MOLAVI_FIXTURE_DIR` is set. Run with PHP 8 or the disposable PHP Wasm CLI.

`tests/storefront_interactions.browser.cjs` runs the generated fixtures in Chromium
with intercepted mock APIs. It checks bounded tiles, quantity labels, no writes on
load, checkout waiting for price saves, delivery updates, list storage/restoration,
mobile width, help dialogs and incremental loading. Screenshots stay in `/tmp`.

Connected release checks must use an isolated anonymous Staging cart, inspect native
product/cart/account/checkout rendering, confirm the fresh quote and restore endpoint,
and empty that test cart afterwards. No order, payment, refund, supplier procurement
or customer notification is needed for these checks. Paid callbacks, real fulfillment
status and customer shortage/refund decisions need their existing connected workflow
and provider; UI rendering is not evidence that these business actions completed.
