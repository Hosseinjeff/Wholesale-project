# MolaviSale WooCommerce storefront

This plugin adds a shared Persian header, footer and warm responsive styling across
the public WordPress/WooCommerce pages, plus a storefront for the existing WooCommerce
catalog. On the WooCommerce Shop page it replaces the default archive with the
screenshot-aligned storefront template. If that same Shop page is set as the static
homepage, the homepage uses the storefront too. It keeps the plugin's server-side
basket quote and WooCommerce checkout.

## Use the storefront in a custom page

For a regular page or Elementor layout, add the **فروشگاه مولوی‌سل** widget. It includes
the catalog, search and category filters, basket, pricing progress, and phone basket sheet.

The same markup can be inserted with `[molavi_storefront]`.

## Product data

Catalog publication sends packaging and category alongside the existing product name,
supplier, minimum quantity, image, and prices. WordPress stores those fields as
`_molavi_packaging`, `_molavi_category`, and `_molavi_supplier`. A catalog republish
fills these fields for existing products and stores supplier cost in private
`_molavi_supplier_cost_toman` and compatibility `_molavi_cost` metadata. The consumer
reference price stays in private `_molavi_market_shelf_toman` metadata. Products without a consumer/reference price
are sent as held products and are not shown in the storefront.

Supplier costs and margins stay private on the server; the product editor shows the
recorded supplier cost only to authorized owners. The storefront displays the
consumer/reference price, current server-calculated unit price, current discount, and
maximum possible discount. Basket progress counts only discountable products; savings
and product subtotal are shown separately.

Favorites are saved in the visitor's current browser and can filter the visible catalog;
they are not synchronized to a customer account.

## Product and order work in WooCommerce

Owners can review MolaviSale context from the existing WooCommerce screens. On
**WooCommerce → Products**, the list shows supplier and supplier-price freshness, with a
freshness filter. The product editor's **مولوی‌سل** tab shows supplier, packaging,
category, minimum quantity, customer reference price, recorded supplier cost, price
verification time, source offer ID, and source product ID. Its links open the matching
reference case or the supplier-price review filtered to that product. Source-managed
values are read-only in this tab; use the review workflow to record a verified change.

On **WooCommerce → Orders**, the MolaviSale column shows handoff status and opens either
the WooCommerce order or that order's MolaviSale preparation workflow. The order editor
also has a MolaviSale panel with payment-handoff status and a link to its focused
preparation view. Actions in that view return to the same order. WooCommerce remains the
order record; the focused view handles existing supplier, substitution, refund, packing,
dispatch and aftercare follow-up actions.

## Tabbed MolaviSale workspace

Open **WooCommerce → خط لوله مولوی‌سل** for one workspace with tabs for extraction,
reference catalog, reference freshness, product matching, supplier prices, publication,
Sheet conflicts, inventory, clearance pricing, and orders. Each tab displays the existing
section content in place, so review forms and actions stay available without switching
between separate menu pages. Tabs work with mouse, keyboard, and narrow screens; the
selected tab is kept in the page URL.

## Order boundary

The continue button opens the current WooCommerce checkout, which remains responsible for
creating orders and triggering the existing order notification. The proposed guest request
form is not active. Availability, final price, and delivery remain subject to the existing
operator confirmation process. The production checkout has no enabled payment method, so
keep WooCommerce in Coming soon mode until a full-prepayment gateway is configured.
