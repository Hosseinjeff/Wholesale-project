# MolaviSale business flow

Updated: 2026-10-25, Asia/Tehran. Design stage: mockup v2; production unchanged.

This document connects the existing ingestion/pricing code to the customer journey.
Code-backed behavior and proposed launch behavior are distinguished below.

## Sources and current scope

The active discovery plan is `workflow-roleplay.md`: the full operating stream, from
supplier intake through processing, publication, orders, fulfillment, and follow-up.
The customer storefront is one stage. Define data authority, operator ownership, safe
automation, and recovery across the stream; do not equate mockup success with integration.

The three existing Markdown files (`README.md`, `deploy_instructions.md`, and
`DESKTOP_INDEPENDENT_README.md`) mostly describe bot/deployment setup. They do not
fully specify the storefront. Pricing and order behavior was therefore checked in:

- `catalog/pricing.py`, `tests/test_pricing.py`: price bands, rounding, quote rules.
- `shop-wp/molavi-shop/includes/pricing.php`: WooCommerce basket eligibility,
  quantities, price calculation, and AJAX quote responses.
- `shop-wp/molavi-shop/widgets/{deal,price,facts,basket}.php`: current UI contracts.
- `catalog/extract.py`, `tests/test_storefront.py`: pack-derived minimum purchases.
- `catalog/sync.py`, `catalog/publish.py`, `shop-wp/molavi-shop/includes/sync.php`:
  price-book and storefront publication.
- `catalog/orders.py`, `shop/cart.php`, `shop/order.php`,
  `shop-wp/molavi-shop/molavi-shop.php`: order storage, review, and bot notifications.

The live WordPress store and older PHP shop coexist. Their checkout/account behavior
differs. A redesign must select one production order path rather than create another.
The mockup is an isolated static artifact and does not connect to either order system.

## Supplier-to-store flow: existing code

Migration status: see `gas-migration-audit.md` and `recovered-gas-audit.md`. The user
supplied the actual reference logic in an eight-file GAS archive. Current Python does
not read that sheet in the reproduced case. The supplied GAS also has a different
inventory authority and fixed-markup storefront formula; migration is not signed off.

### Intended reference-assisted input, clarified by the user

The user confirms an existing reference process writes `ReferencePrices`. The model
has two distinct inputs: (1) messages forwarded to the bot, analyzed
and recorded in Sheets, and (2) a source reading a well-known wholesaler as a reference
anchor to help extract those imported posts correctly. The recovered source includes
a legacy Rezvan Pakhsh PDF importer and a main refresh handler delegating to a separate
server. The active server source/configuration and installed jobs are not supplied.
The export defines a 21-column sheet, weighted matching, unit/carton assessment,
category/history fallbacks and operator confirmations. Eighteen offline observation
groups reproduce useful behavior and defects; they do not verify a live integration.
The extraction-reference source is distinct from a product's consumer/reference price
used in the discount formula below. Do not overwrite supplier-offer facts using the anchor
without an explicitly agreed field-authority and review policy.

### Recovered GAS alternative: resolve before implementation

The GAS archive writes supplier offers and owned stock movements to `Inventory log`,
then builds a `storefront` sheet. Its default fixed markup is 20% for owned stock or
15% for supplier offers, rounded up to 1,000 toman. Current Python/Woo uses the basket
price bands below. These formulas are different evidence, not approved competing
prices. Keep the user-required maximum/current basket discounts and explicitly decide
which data/pricing/publication owner will serve them. Do not silently replace either
pipeline or retire the reference jobs while designing the UI.

### Observed Python checkout pathway

1. An operator forwards a supplier post/photo to the bot.
2. The service extracts products, supplier prices, consumer prices, pack information,
   and photos. A clear price is saved directly; ambiguous cases go through review.
3. Google Sheets keeps message history, the offer history, and the latest product book.
4. Configured pricing rules calculate a permitted selling-price range for each product.
5. Catalog publication sends products, settings, and images to the storefront. The
   WordPress synchronization preserves source IDs and updates matching products.
6. A registered order can be recorded in order/order-line sheets and notified to operators.

Publication, inventory certainty, bot authorization, and live synchronization are
integration concerns; the visual prototype does not prove them.

## Customer pricing: existing rules

Each eligible product has its own consumer/reference price, opening selling price,
minimum selling price, and maximum discount. Supplier cost and margin rules determine
the range; those internal values should not be presented as customer-facing content.

Let `R` be the reference price, `C` the opening price, `F` the minimum selling price,
and `p` the basket progress:

```text
eligible_reference_total = sum(reference_price × quantity for eligible basket items)
progress = clamp(eligible_reference_total / unlock_target, 0, 1)
current_unit_price = ROUND_HALF_UP(C + (F - C) × progress)
maximum_discount = (R - F) / R
current_discount = (R - current_unit_price) / R
```

Prices remain within the permitted price band. Currency is integer toman; rounding
must match the existing pricing engine, not browser default floating-point formatting.

Important consequences:

- An empty basket has progress zero, but eligible products can already have an opening
  discount. Zero progress does not mean zero discount.
- Adding or removing a product changes current prices across the basket and catalog,
  including products not currently selected. Each product still has its own rate.
- A maximum discount is a ceiling of opportunity, not a discount already applied.
  The current discount is already included in the shown unit price; do not subtract it twice.
- WooCommerce measures progress using reference value of **discountable** products.
  Fixed-price products can be purchased but do not advance that progress.
- The default unlock target in code is 2,000,000 toman. It is configurable and is only
  a sample value in this mockup, not a newly verified live business setting.
- That target is not a minimum order value, delivery threshold, payable total, or coupon.
  Reaching it gives each eligible product its own maximum discount.
- Basket savings are `sum((reference - current_unit) × quantity)`.
  The overall basket percentage is savings divided by total reference value, a weighted
  result rather than the average of the visible product percentages.
- Once progress reaches one, further additions cannot push unit prices below their floors.

The Python public catalog filters out non-discountable products; its `quote_lines`
therefore measures all supplied lines. The WordPress implementation explicitly checks
discount eligibility. Cross-platform tests must account for this input-contract difference.

### Minimum quantities

The current extraction defaults pack sizes of 6, 12, or 24 to a minimum purchase of
6; other examples default to 1. Product-specific minimums are carried into the store.
That is a minimum, not a multiple-of-pack restriction: after the initial minimum,
the current shop changes quantity one unit at a time. Reducing below the minimum
removes the line and should explain the removal and repricing.

## Proposed first-release customer flow

The latest user choice on 2026-10-25 is to cover both customer types, starting with a
household buying groceries in bulk and then a small shop buying stock. Seller/fulfillment responsibility and launch coverage remain open in WF-07/08.

User direction, 2026-10-22: payment solutions in Iran are a future task; the first
solution should be fast and hassle-free. The proposed launch interpretation is:

1. Browse/search/filter without logging in.
2. See maximum and current discount on every eligible product, beside pack/minimum details.
3. Add the minimum quantity; adjust quantities and see immediate price/savings changes.
4. Open the basket, review quantities and the quoted product subtotal.
5. Continue as a guest with a name and mobile number; avoid requiring email or an account.
6. Submit a request for availability, final price, and delivery coordination.
7. The operator reviews and confirms; payment arrangements follow confirmation.

Guest request collection is a **design proposal**, not an already implemented production
endpoint. Existing PHP checkout requires an account and email; changing that is future work.
The mockup's request button only displays a clearly labeled demonstration message and
never reads, transmits, or stores entered contact values.

### Order states and money

Existing order code has `new → review → confirmed`, with `cancelled` as another status.
An initial request is not a paid order or proof of guaranteed availability. The
prototype labels the visible total as the product subtotal after discounts; delivery
is determined later. It makes no free-delivery or payment-security promises.

Before a real launch, confirm how long the displayed quote is honored, how stock is
checked, how substitutions are approved, and how customers receive confirmation.

## Walkthrough for discussion

The prototype starts with two illustrative items:

| State | Reference basket value | Progress | Product subtotal | Savings |
|---|---:|---:|---:|---:|
| 4 teas + 10 energy drinks | 1,000,000 | 50% | 756,040 | 243,960 |
| Remove the 10 drinks; keep 4 teas | 400,000 | 20% | 307,600 | 92,400 |
| 20 teas | 2,000,000 | 100% | 1,090,000 | 910,000 |

At the initial state, tea's maximum is 45.5% and its current discount is 31.5%.
The drink's maximum is approximately 27.7% and its current discount is approximately
19.7%. These are sample fixtures calculated from the actual pricing functions.
Removing drinks raises the price of tea; the UI must explain that before a real request.

## Decisions still needed before production

- Quote validity and whether supplier prices are rechecked at confirmation.
- Actual delivery coverage, charges, and who handles delivery.
- Stock verification, partial availability, substitutions, cancellations, and returns.
- Customer confirmation/tracking channel; whether accounts are optional at launch.
- Operator access and notification destination for incoming requests.
- A payment method/provider, settlement flow, and applicable requirements, as a separate task.
- Migration to a single storefront/order path, with regression checks for Sheets and Telegram.

No credentials, private customer data, or live supplier cost values belong in these docs
or the public mockup. `design-decisions.md` records the UI details and verification.
