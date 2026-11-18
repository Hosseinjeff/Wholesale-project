# Storefront upgrade review

Reviewed 2026-11-15, Asia/Tehran. Scope: WordPress Staging storefront, current plugin
source and the confirmed business decisions. This is an assessment; no storefront,
pricing rule, catalog, cart, order, payment or configuration was changed.

## Business the storefront must explain

MolaviSale serves households buying groceries in larger quantities and small shops
restocking. Purchasing is demand-led: suppliers may impose carton quantities, while
customers buy in their approved smaller increments. Owned surplus is a separate stock
position and may later have batch-specific clearance prices.

The selected buyer type changes minimum quantities, increments and discount ceilings.
Current discounts are already included in the displayed price. Eligible basket value
changes the prices of qualifying products; fixed-price products do not contribute to
that discount progress. The household discount target follows the tuna anchor, and the
shop target is four times that target. Free delivery is a different rule: 5,000,000 toman
of discounted goods subtotal, with an initially agreed 250,000-toman Tehran charge below
it. These are confirmed initial settings, not a claim that all current live shipping
configuration was verified during this review.

The agreed ordering path is guest checkout with name, mobile and delivery address,
optional email/account, direct payment and verified full payment before supplier
procurement. Earlier documentation describing routine manual quote confirmation is
superseded by D-020. Delivery is consolidated and owner-arranged in Tehran. Neither a
recent supplier price nor Woo's in-stock flag proves physical stock is owned or ready
to dispatch. The internal Rezvan comparison catalog is distinct from a verified
customer-facing consumer price.

## Current evidence

Read-only HTTP checks returned 200 for `/`, `/shop/`, two product-detail pages, `/cart/`
and `/my-account/`. The empty-cart `/checkout/` request redirected to `/cart/`; checkout
fields, available payment methods and a paid transaction were not verified.

The public Woo Store API returned 30 products, all flagged purchasable/in stock. All
30 had no assigned customer categories and no Woo attributes. All 30 had empty full
descriptions; 24 also had no short description. Two had no product image. The category
endpoint returned only `Uncategorized` with 30 products. The custom catalog also
rendered 30 blank categories and an empty category-filter explanation. These are
publication gaps; they do not prove the original supplier records lack these facts.

The catalog's featured item advertised a maximum discount of 1.7% during this check.
Many fixed-price cards repeated `قیمت ثابت`, a 0% discount, and equal price values.
The empty basket displayed a household discount target of 5,280,000 toman and a shop
target of 21,120,000 toman. It did not communicate the separate agreed delivery rule in
its rendered summary.

Product, cart and account HTML included the Molavi header alongside default Twenty
Twenty-Five navigation, English controls, sample links and default footer content.
The sampled product pages showed `Uncategorized`, English add-to-cart and a largely
empty reviews interface. The catalog and these pages therefore present different
navigation and content structures. This review inspected HTML and source; it was not
a live visual, keyboard or mobile usability test.

The rendered household minimum was one for 27 cards and four for three cards. This
alone does not establish an error: product overrides and unknown pack sizes are valid.
It does warrant checking source pack metadata against the approved quantity table.
One sampled title named a 24-piece pack but had no public structured attributes or
variant selector explaining the unit being purchased.

Three deployed frontend files differed from their tracked versions:

| File | Deployed bytes | Tracked bytes |
| --- | ---: | ---: |
| `includes/storefront-renderer.php` | 43,382 | 21,888 |
| `shop.js` | 88,477 | 42,230 |
| `storefront.css` | 140,863 | 56,032 |

The changed deployed source was read and preserved under `/tmp/molavi-storefront-review`
for this assessment. Reconcile that source with Git before implementing upgrades so
current deployed behavior is preserved intentionally. No reconciliation was performed
as part of this review.

## Recommended upgrade order

| Priority | Upgrade | Required behavior |
| --- | --- | --- |
| First | One shopping interface | Share Persian RTL header, navigation, typography, controls and footer across catalog, product, cart, checkout, account and order status. Remove default sample/theme navigation from the customer journey. |
| First | Publish complete product facts | Show exact size/weight, what one unit means, pack size, selectable flavor/model when supported, chosen buyer minimum/increment, and the cost of the minimum selection. Keep unsupported attributes absent rather than inventing them. Resolve missing photos and distinguish actual selectable variants from assorted packs. |
| First | Useful discovery | Map verified products into a small grocery taxonomy such as drinks, pantry, canned goods, snacks and tea. Search across names, brands, Persian character variants, sizes and confirmed variants. Current-price and unit-price sorting must state what they compare. |
| First | Clear basket economics | Keep payable unit price prominent. Label current and maximum discounts separately; show one fixed-price label for non-discountable items. Explain price changes after basket edits and keep the server quote authoritative. Show discount progress separately from delivery eligibility. |
| First | Complete the checkout journey | Present delivery coverage, applicable charge, final payable amount and guest fields clearly. Verify quantity/profile transitions, shipping calculation, installed payment methods, payment return, durable order receipt and mobile order recovery on Staging before release. Preserve the agreed direct-checkout flow. |
| Next | Availability and aftercare | Show a verified distinction between ready stock and goods sourced after ordering where the underlying data supports it. Communicate delivery timing only when operationally supported. Give paid customers order status, support access and explicit replacement/refund choices for shortages; preserve the accepted delivery charge according to the agreed policy. |
| Next | Merchandising and repeat buying | Use clear category entry points and relevant product discovery. Make a featured discount conditional on a meaningful real benefit. Build reusable shopping lists, favorites and reorder flows with current-price/availability revalidation; prioritize fast restocking for shops and repeat essentials for households. |
| As catalog grows | Scalable delivery of the interface | Replace unlimited server product rendering and complete-catalog payloads with bounded catalog reads. Cache stable catalog facts separately from personalized quotes, constrain image sizes, and prevent older price responses from overwriting newer basket state. Measure real page performance before choosing a framework. |

## Suggested customer card

Product photo → exact name and size → quantity/unit meaning → current unit price →
current discount with maximum as secondary information → buyer minimum and minimum
selection total → an explicit “Add N” action. Additional details can open in the same
product presentation. A small shop may benefit from a dense list view using the same
pricing and product facts; household shopping should remain the initial default.

A short purchase explanation should answer three questions near the storefront entry:
what can be bought here, how larger qualifying baskets change prices, and where orders
are delivered. Detailed calculation rules belong behind help controls. The buyer picker
should identify a household purchase versus buying for a shop, without resembling a
separate store-navigation link.

## First implementation package

1. Preserve/reconcile deployed frontend source, then establish shared page chrome.
2. Repair the publication of categories and structured buying units for the existing
   30 products, and verify explicit overrides against the approved quantity policy.
3. Rework the shared product card and product detail using those fields.
4. Simplify basket pricing and expose the separate delivery calculation.
5. Run the agreed guest shopping-to-order journey on Staging, including supplier
   availability changes and payment failure/retry behavior.
6. Add repeat-purchase tools and larger-catalog performance work after that journey
   is coherent.

The supplier-message and reference-processing dashboard remains private. Customer
pages should translate its verified outcomes into useful buying facts rather than
expose internal queue names, extraction states, supplier costs or reference-match IDs.
