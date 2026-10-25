# MolaviSale roleplay plan

> Superseded as the active plan by `workflow-roleplay.md` (v2.0). This customer-focused
> outline is retained as supporting detail. Resume using WF-00…WF-11 from the full
> supplier-data-to-order-aftercare workflow, not the RP scene sequence below.

Version 1.2. Updated 2026-10-25, Asia/Tehran. Status: RP-00 in progress.
The user selected both customer types: household buying groceries in bulk first,
then a small shop buying stock.

Purpose: agree the complete real-world buying and operating process before approving
the revised design or implementing the production ordering flow. Use the roleplay to
discover requirements, not merely to rehearse the current website.

## How we will run it

The user is the business decision maker. The assistant plays one named role at a time:
customer, site, operator, or supplier. The user can choose to play the customer instead.
Start with one customer persona and repeat relevant steps for a second persona later.

Each scene follows this format:

1. Set a short realistic situation and identify who is speaking.
2. Show what that person sees, does, or receives. Use sample data only.
3. Ask one concrete question needed to move that scene forward.
4. Record the answer, the resulting business rule, and the required screen/message.
5. Try the important exception for that scene, then record any unresolved issue.
6. Update the decision log and roleplay state before moving to the next scene.

Do not treat a suggested answer or inferred assumption as an approved business rule.
Do not submit real orders, send notifications, change the store, or research/choose a
payment provider as part of this exercise.

## Known requirements to preserve

- Persian RTL storefront: clean, crisp, professional, minimal, with a more vibrant palette.
- Both desktop and phone experiences are required.
- Each product has a distinct maximum discount and a current basket-dependent discount.
- Current discounts are already included in displayed unit prices.
- Adding/removing eligible items reprices other items as well; item minimums remain visible.
- Payment solutions in Iran are a future task; the first experience should be fast and easy.
- Mockups come before production changes. Keep project documentation current and shareable.

The current guest-request proposal is a working assumption in the design, not a final
user-approved specification. Existing code and customer-policy decisions are distinct.

## Scene sequence

### RP-00 — Buyer and launch boundary

**Goal:** choose who we are serving first and what the first release actually offers.

**Opening question:** "Are we starting with a household buying groceries in bulk,
a small shop buying stock, or both?"

**Recorded answer:** both customer types, starting with a household buying groceries
in bulk and then a small shop buying stock. The next question is who
sells/fulfills that household's basket: MolaviSale or the individual suppliers.

**Resolve:** buyer type; normal basket size; customer location; who supplies the goods;
whether MolaviSale sells, coordinates, or aggregates requests; meaning of 'order'.
Start with the persona decision; ask the remaining questions individually during discussion.

**Exit:** a one-paragraph first-release promise with explicit non-goals.

### RP-01 — Supplier offer becomes a sellable product

**Roles:** supplier → operator → catalog.

**Situation:** a supplier sends an offer with price, reference price, pack size, and photo.

**Resolve:** what the operator verifies; treatment of unclear prices; photo/brand accuracy;
how stock and offer freshness are checked; which products may be published; who can publish.

**Exception:** missing consumer price, unclear pack units, or a supplier changing a price.

**Exit:** publication checklist and owner; what customers can safely be told about availability.
The existing bot/Sheets pathway is a source of implementation evidence, not a guarantee
that a supplier offer proves live stock.

### RP-02 — First visit and product discovery

**Roles:** new customer on a phone → site.

**Opening:** "I arrived from a link. What do you sell, and why should I buy here?"

**Resolve:** homepage promise; search/category entry points; package/unit labels; supplier
visibility; minimum-purchase explanation; contact/help path; optional account behavior.

**Exception:** a desired product is absent, or search yields no results.

**Exit:** agreed homepage/card content and next action, understandable without a tutorial.

### RP-03 — Basket building and both discount rates

**Roles:** customer → pricing UI.

**Situation:** compare a product's maximum discount against its current price/discount,
add another item, change quantity, and remove an item.

**Resolve:** visible labels for reference/current price, maximum/current discounts,
minimum quantities, savings, and progress; how to explain prices rising after removals.
Confirm any intended business changes separately from the code-backed rules.

**Cases to walk through:**

- Empty basket, with opening discounts already visible.
- First addition automatically uses that product's minimum quantity.
- A second eligible item lowers the first item's current unit price.
- Removing an item increases another item's price and reduces savings.
- Reaching the unlock target activates each product's own maximum discount.
- Adding more after the target never lowers a product below its floor.
- Fixed-price products do not advance discount progress in the WordPress implementation.

**Exit:** customer can explain the two rates and the payable product subtotal correctly.
The target is a discount-progress threshold, not a minimum spend or free-delivery threshold.

### RP-04 — Fast request submission

**Roles:** customer → site → receiving operator.

**Opening:** "My basket is ready. What is the least I must do to send it?"

**Working proposal:** guest name + mobile number; no required account/email; review first;
send a request for confirmation rather than imply an already paid/guaranteed order.

**Resolve:** required/optional fields; address timing; whether phone verification is needed;
what submission commits either party to; where the request is recorded; who receives it;
what confirmation and reference the customer gets; expected response time.

**Exceptions:** invalid contact details, double-tap/duplicate request, or network failure.

**Exit:** exact submission fields, button wording, request receipt, and retry behavior.

### RP-05 — Operator review, supplier check, and customer approval

**Roles:** operator → supplier → customer.

**Situation:** a request arrives; the operator checks stock, latest prices, and delivery.

**Resolve:** ownership; quote validity; when prices lock; contact channel; confirmation
deadline; how quantities or suppliers can change; explicit customer approval of a revised total.

**Exception:** one item is unavailable, partial quantity remains, or a price has changed.
Recompute discounts after changing lines; do not silently keep an invalid previous quote.

**Exit:** review/confirmation checklist and approved change/rejection messages.

### RP-06 — Payment handoff and delivery

**Roles:** confirmed customer → operator → delivery contact.

**Resolve now:** when payment is requested, how confirmation is evidenced, what delivery
details are needed, who quotes/pays delivery, and how the customer receives updates.
Treat the eventual payment method as an open dependency; do not pick an Iranian provider yet.

**Exceptions:** payment not completed, customer cannot be reached, delivery unavailable,
or delivery fee changes the amount the customer must approve.

**Exit:** a complete manual first-release handoff that does not depend on a gateway choice.
If the business cannot yet commit to a step, label the dependency rather than invent a policy.

### RP-07 — Tracking, cancellation, and aftercare

**Roles:** returning customer → support/operator.

**Opening:** "Where is my request, and how can I change or cancel it?"

**Resolve:** tracking without forcing an account; which statuses are customer-visible;
allowed edits/cancellation windows; partial fulfillment; complaints, returns, and refund handling.

**Exception:** duplicate request, missed confirmation, damaged/missing item, or cancellation
after fulfillment work has started. Payment/refund rules remain method-dependent where applicable.

**Exit:** lifecycle/status map with owners and customer messages. Existing code's
`new/review/confirmed/cancelled` states may need extension; new states are proposed until implemented.

### RP-08 — Replay the agreed journey on desktop and phone

**Roles:** customer using the revised interactive mockup.

**Tasks:** discover a product, distinguish both discounts, add/change/remove items,
review the basket, submit a demonstration request, and locate help/status information.
Repeat on a narrow phone viewport, not a scaled desktop image. Then replay the relevant
scenes for a small shop, checking quantity/pack needs, repeat ordering, supplier visibility,
and any invoice requirements without assuming household and business policies are identical.

**Exit:** each task has an observable acceptance criterion. Classify gaps as design,
business-policy, integration, or deferred payment work. Produce the implementation backlog
and request visual approval of the completed mockup before changing production.

## What each completed scene produces

| Record | Contents |
|---|---|
| Business decision | Chosen behavior, why, owner, and explicit confirmation status |
| Customer UI | Screen, label, fields, feedback, empty/error/success states |
| Operator workflow | Who acts, what they verify, when, and through which channel |
| Acceptance criterion | Given a situation, the observable expected result |
| Open dependency | Missing policy/integration, impact, owner, next action |

Example acceptance criterion: "Given a basket below the discount target, when a second
eligible product is added, the current price of the first product updates while its
maximum-discount badge stays unchanged."

## Sample pricing script

Use the illustrative, code-derived examples in `business-flow.md`:

| Scene | Reference value | Progress | Product subtotal | Savings |
|---|---:|---:|---:|---:|
| 4 teas + 10 drinks | 1,000,000 | 50% | 756,040 | 243,960 |
| Remove the drinks | 400,000 | 20% | 307,600 | 92,400 |
| 20 teas | 2,000,000 | 100% | 1,090,000 | 910,000 |

In the first row, tea has maximum 45.5% and current 31.5%; drinks have maximum about
27.7% and current about 19.7%. These are fixtures, not live prices or confirmed live settings.

## Completion and continuity

The roleplay is complete when RP-00 through RP-08 have recorded outcomes, critical
exceptions have an agreed response or explicit blocker, and the first-release scope
can be implemented without guessing customer policy. A roleplay does not prove real
pricing, payment, stock, notification, or fulfillment integrations work.

After each scene, update `roleplay-state.json`, `decisions.md`, `project-handoff.md`,
and any affected business/design document, then refresh the shared brief. Keep scene
IDs stable across chats. If editing/publishing is unavailable, return a checkpoint
the user can carry to the next chat; do not claim persistent updates occurred.
