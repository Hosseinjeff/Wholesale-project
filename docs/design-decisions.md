# Storefront design decisions

Updated: 2026-10-29, Asia/Tehran. Status: the v2 visual and pricing direction is implemented on the WordPress Shop page; visual/business review is still pending.
The storefront remains behind WooCommerce Coming soon until a full-prepayment gateway is configured.

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

Warm white surfaces, a dark neutral type color, generous but practical spacing, fine
borders, consistent product-image frames, and locally bundled Vazirmatn typography.

| Color | Role | Value |
|---|---|---|
| Orange | Main actions and brand | `#D94419` |
| Raspberry | Maximum-discount badge | `#B82058` |
| Teal | Current discount and realized savings | `#08766B` |
| Violet | Progress toward maximum discounts | `#6043A9` / `#7951BD` |
| Ink | Primary text | `#243239` |
| Warm white | Background | `#FAFAF9` |

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

The WordPress plugin implements the v2 visual and pricing direction and is installed on
WooCommerce Shop page 7 through `[molavi_storefront]`. It uses the live Woo catalog,
server-calculated basket quote, responsive product grid, separate maximum/current
discount labels, basket progress, and the phone basket sheet. The live WordPress render
returned two product cards. The public site remains in Coming soon mode.

The mockup's guest-request form remains a demonstration; the production storefront
continues to the existing WooCommerce checkout. The design remains unapproved pending
visual/business review. Full production readiness still requires a full-prepayment
gateway, live order/payment/Sheets/Telegram verification, and end-to-end workflow review.
Do not treat the mockup's illustrative catalog or a rendered storefront as proof of those
handoffs.

## Revision record

- v1: static desktop direction, green/white palette, illustrative product grid and basket.
- v2: interactive responsive prototype; separate maximum/current discounts, actual pricing
  formula applied to illustrative fixtures, vibrant semantic colors, phone basket, guest
  request proposal, and business-flow documentation. Verification results pending build check.
- 2026-10-29: production WooCommerce storefront implemented from the v2 visual/pricing
  direction and connected to Shop page 7; public launch remains gated on full prepayment.
