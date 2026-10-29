# MolaviSale WooCommerce storefront

This plugin adds a responsive storefront widget for the existing WooCommerce catalog.
It uses the plugin's server-side basket quote and the existing WooCommerce checkout.

## Add the storefront to the shop page

In Elementor, edit the page or archive used for the shop, remove its old product grid and
basket columns, and add the **فروشگاه مولوی‌سل** widget. The widget includes the catalog,
search and category filters, basket, pricing progress, and the phone basket sheet.

The same markup can be inserted in a WordPress page with `[molavi_storefront]`.

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

## Order boundary

The continue button opens the current WooCommerce checkout, which remains responsible for
creating orders and triggering the existing order notification. The proposed guest request
form is not active. Availability, final price, and delivery remain subject to the existing
operator confirmation process. The production checkout has no enabled payment method, so
keep WooCommerce in Coming soon mode until a full-prepayment gateway is configured.
