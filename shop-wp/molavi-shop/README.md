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

Supplier costs and margins stay server side. The storefront displays the consumer/reference
price, current server-calculated unit price, current discount, and maximum possible
discount. Basket progress counts only discountable products; savings and product subtotal
are shown separately.

Favorites are saved in the visitor's current browser and can filter the visible catalog;
they are not synchronized to a customer account.

## Order boundary

The continue button opens the current WooCommerce checkout, which remains responsible for
creating orders and triggering the existing order notification. The proposed guest request
form is not active. Availability, final price, and delivery remain subject to the existing
operator confirmation process. The production checkout has no enabled payment method, so
keep WooCommerce in Coming soon mode until a full-prepayment gateway is configured.
