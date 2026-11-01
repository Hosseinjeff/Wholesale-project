# Storefront design decisions

Updated: 2026-10-29, Asia/Tehran. The user's latest screenshot is the active storefront reference. The screenshot-aligned storefront is deployed on `/` and `/shop/`; both routes return HTTP 200 and the live markup includes the custom header, catalog, and basket. The user has explicitly taken the site live; payment configuration is outside the current implementation scope.

## Discovery scope

The user clarified that discovery must cover the entire operating stream: supplier
data entry, processing, site publication, efficient customer UI, order receipt and
management, fulfillment, and follow-up. Use `workflow-roleplay.md` (WF-00…WF-11).
A customer storefront mockup is one deliverable; the operator work surface and
exception queues must also be specified before claiming workflow completeness.

## User requirements and revisions

The first mockup established a clean, professional, minimal RTL storefront. Feedback:

1. Preserve the business model: show both each product's maximum discount and the
   current discount determined by the basket.
2. Show the phone layout as well as desktop.
3. Use a more vibrant palette while preserving clarity.
4. Read and maintain project documentation; clarify the customer journey where needed.
5. Payment-provider exploration is future work; prioritize an easy first ordering flow.
6. Cover both customer types: household buying groceries in bulk first, then a small shop. Use the
   shared roleplay plan and decision log to finalize policies before implementation.

The initial static image used illustrative prices and was delivered through a public
preview URL after inline chat images and local-file links failed to display. Future
review artifacts must have externally verified browser URLs.

## Design direction

Use the user's latest screenshot for the storefront composition: a compact RTL header with
brand, navigation, search and account/favorite/cart controls; four desktop product columns;
a right-side persistent basket; and a two-column phone catalog with a bottom basket action.
Keep warm white surfaces, dark neutral type, fine borders, consistent product-image frames,
and locally bundled Vazirmatn typography.

| Color | Role | Value |
|---|---|---|
| Orange | Main actions and brand | `#FF6B21` |
| Raspberry | Maximum-discount badge | `#F13D5D` |
| Teal | Current discount and realized savings | `#08766B` |
| Violet | Progress toward maximum discounts | `#6043A9` / `#7951BD` |
| Ink | Primary text | `#243239` |
| Warm white | Background | `#F7F3EC` |

Color is reinforced with words. A customer must not need to distinguish colors to
understand maximum versus current discount. No unsupported promotional or delivery claims.

## Product card information hierarchy

1. Product image; distinct raspberry `تا …٪ تخفیف` maximum badge, or `قیمت ثابت`.
2. Name, packaging, supplier, and minimum purchase.
3. Consumer/reference price, struck through when discounted.
4. Current unit price in toman, with `تخفیف فعلی …٪` explicitly labeled next to it.
5. Add button that names the initial minimum quantity; selected products show a stepper.

Current prices/rates update across all products when the basket changes. The maximum
badge remains unchanged. Percentage display uses one decimal where needed; full precision
drives integer-toman prices. A weighted basket savings rate is labeled separately.

## Basket and progress

The basket shows line quantities and totals, original/reference total, realized savings
in toman and percentage, and product subtotal after discounts. The progress module is
visually separate from savings. It explains that its remaining amount uses reference
value of eligible products; the help dialog expands the rule.

At full progress it states that maximum discounts are active. At an empty basket it
provides an explicit next step and disables review. Removing a line below its minimum
shows a status message and updates prices. No hidden step-size requirement is invented.

## Desktop and phone

- Wide desktop: four product columns and a persistent basket sidebar.
- Medium widths: three or two product columns as available width changes.
- Phone: two product columns; a fixed bottom basket button with subtotal and savings.
- The phone basket opens as a bottom sheet with totals, pricing progress, and editable
  lines. It closes with its close button, backdrop, or Escape, traps keyboard focus,
  and returns focus to the opener.
- Input, controls, dialogs, and sticky bars respect narrow widths. Reduced-motion
  preference and safe-area bottom padding are supported.
- The preview viewer has Desktop and Phone buttons; the direct mockup responds to
  the actual browser width. The phone frame is a real 390px iframe, not a scaled desktop image.

## Guest request proposal

`بررسی و ادامه` opens a basket review followed by only name/mobile fields. No required
account or email. The next action is `ثبت درخواست`; availability, final price, and
delivery are confirmed before payment. The isolated demonstration displays a sample
confirmation only. A future production implementation needs server validation, abuse
protection, consent/retention decisions, and reliable order recording/notification.

## Artifact and reproducibility

Source: `design/mockup-v2/{index.html,store.css,pricing.js,store.js,review.html,build.py}`.

```bash
python design/mockup-v2/build.py /workspace/scratch/mockup-v2
```

The builder computes public sample price bands using `catalog.pricing.price_band` and
embeds the existing repository's illustrative photos and fonts. Output is a self-contained
HTML file with no API calls, analytics, order submission, or server dependency. Internal
supplier costs are excluded from the public data payload. The public review upload
contains only static preview files and these intentionally public design documents.

The first preview and production theme/plugin remain intact. Mockup data is illustrative;
the current live inventory, pricing settings, payment availability, and stock are not inferred.

## Verification and production boundary

The plugin replaces the WooCommerce Shop archive with a dedicated template on the Shop
route and the same Shop page when configured as the homepage. It uses the live catalog,
server-calculated basket quote, maximum/current discount labels, basket progress, favorites,
list/grid control, and product thumbnails in the basket. Live HTML checks confirm the new
markup on `/` and `/shop/`; a browser inspection confirmed the header, toolbar, grid, and
right-side basket. The catalog currently has two real products, so no mockup products were
added. The CSS uses four columns on wide screens, three at 1360px and below, and two at
1080px and below. Search, sort, favorite, and cart controls are implemented; business,
shipping, and payment readiness remain separate work.

The mockup's guest-request form remains a demonstration; the production storefront
continues to the existing WooCommerce checkout. Visual/business review and connected
non-payment handoffs remain pending. The public rendering does not prove shipping-zone
coverage or full workflow readiness; the current Tehran zone has no configured locations.
Payment setup and payment validation are outside the scope of the current work.

## Revision record

- v1: static desktop direction, green/white palette, illustrative product grid and basket.
- v2: interactive responsive prototype; separate maximum/current discounts, actual pricing
  formula applied to illustrative fixtures, vibrant semantic colors, phone basket, guest
  request proposal, and business-flow documentation. Verification results pending build check.
- 2026-10-29: user supplied the current target screenshot; the compact header, toolbar,
  responsive product grid, product cards, and basket are deployed while retaining live
  catalog and pricing behavior. Browser review confirmed the main regions are rendered.
