# MolaviSale — complete-project execution plan

Version 3.8 draft, 2026-10-28 (Asia/Tehran). Replaces incremental implementation as
the working approach; it does not replace confirmed requirements or historical evidence.
Status: full workflow proposed; remaining owner/clearance/aftercare decisions are
listed in operations-and-ownership-plan.md. Not approved for production. Mockup v2 remains unapproved. No WF scene is newly marked complete.

## Outcome and working agreement

Deliver a coherent operating system: supplier message → reference-assisted extraction
→ exception review → controlled pricing/publication → phone/desktop shopping → durable
customer request/order → supplier/customer coordination → payment handoff → fulfillment
→ customer tracking/aftercare → daily reconciliation and recovery.

Execute as one coordinated implementation and validation effort after the plan's business
choices are settled. Internal dependencies still determine order of work, but do not stop
for decisions about routine technical details or present every component as a new project.
Use the existing WordPress/WooCommerce/MolaviShop installation and supported plugin APIs.
Keep production unchanged until explicit instruction to deploy/activate. Prepare a complete,
reviewable release before that final step. Do not interpret silence as policy approval.

Roleplay is the business acceptance test of the complete journey. It can expose changes
to the plan, but is not a reason to perfect one technical component before showing the
whole flow. Household first, small retailer second. Use sample data throughout acceptance.

## Confirmed constraints

- Required GAS execution moves to the server; preserve the recovered original export.
- Primary input is supplier messages forwarded to the existing bot, saved with provenance. The user may directly correct supplier/stock data in Sheets; supported updates must flow both ways (D-029).
- Rezvan Pakhsh PDF → Gemini → ReferencePrices is the source-backed migration candidate.
  The separate refresh-server wrapper is conditional on evidence of actual use.
- References reach the decision threshold at 24 hours. On stale/failed refresh, ask the
  business owner for that occurrence: pause/review or use the previous snapshot with a
  warning. Wait before affected automatic reference interpretation. Keep decisions scoped.
- Owner choices belong in the private WordPress dashboard, integrated with existing plugins. Complete coordination with the current site plugins and use of their capabilities is a release requirement (D-032).
- Preserve separate maximum/current product discounts and the existing basket price-band
  model. Current price already includes current discount. Progress uses eligible reference
  value; basket savings are weighted. Do not silently substitute the GAS fixed-markup rule.
- Mockup before live redesign; responsive phone and desktop, clean minimal UI with vibrant
  palette, clear units/minimums and next actions. No invented stock/delivery promises.
- Notify the owner when a batch clearance price approaches its purchase price, before the boundary where possible; a below-purchase-cost clearance price requires owner approval (D-031). The proximity threshold remains to be set.
- An eligible clearance batch must have a lower selling price (larger discount) than its normal-period price under the same basket conditions; the existing basket formula remains the baseline (D-028).
- Clearance pricing must consider batch purchase date, production date if available, expiry date and purchase cost, plus the current market price (D-026/D-027).
- Purchasing is demand-led but most suppliers impose fixed selling packs/quantities recorded in their posts (D-014). Necessary pack rounding creates owned surplus stock; manage it with expiry-aware clearance relative to current selling price (D-013).
- Tehran delivery uses one fixed fee below the free-delivery threshold (D-022), and is free to the customer at the threshold (D-018/D-019). Initial threshold is 5,000,000 toman of goods subtotal after product discounts, excluding delivery (D-019 tuna-pack benchmark); fixed fee is initially 250,000 toman (D-023); do not reuse the product-discount target automatically.
- Initial delivery territory is Tehran, with customer delivery arranged by the owner (D-016). This does not imply a dedicated fleet, surrounding-city coverage or a time guarantee; the fixed customer delivery fee is specified separately.
- First-release fulfillment uses consolidated delivery (D-012): suppliers → consolidation/packing → customer. Direct-supplier and hybrid delivery paths are excluded from launch scope.
- Supplier prices warn at 7 days and block purchases at 14 days since last verification, initially (D-021); settings remain editable and distinct from reference freshness.
- Guest checkout with name/mobile/delivery address is supported; account and email are optional (D-025).
- Customers proceed from cart/checkout directly to payment without routine manual order reconfirmation (D-020). Full checkout payment must be verified before committing supplier procurement (D-015). Deposits and pay-on-delivery are outside this first-release procurement flow.
- Do not introduce a new payment provider by default. Verify the installed payment integration for normal checkout, sandbox behavior and signed/idempotent callbacks; the earlier manual request/quote/payment proposal is superseded. A new gateway choice remains deferred unless existing integration cannot meet the approved flow.
- Conserve usage: reuse audits and committed source; targeted reads/tests, no repeat uploads.

## Decisions needed to finalize the business flow

| Question | Recommended proposal, not confirmed | Why it changes the plan |
|---|---|---|
| Operating model | Customer buys from MolaviSale; suppliers supply it; MolaviSale coordinates fulfillment | Determines customer responsibility, supplier split, support and refund ownership |
| Record ownership and conflict policy | All normalized Products data columns are editable (D-034); source/provenance identity is protected; divergent field edits wait for an owner choice. WooCommerce orders remain the recommended order authority | Preserves existing Sheet editing while avoiding lost corrections and conflicting masters |
| Checkout access policy | Guest checkout with name/mobile/address; account/email optional (D-025). Direct payment and full payment before procurement confirmed | Secure order status/recovery and communication channel remain to design |

Initial delivery area is now Tehran and delivery is owner-arranged (D-016). Specify
exact coverage boundaries, packing responsibility, delivery charges and scheduling. These are launch settings and
operational responsibilities, not a reason to postpone independent code work. Do not invent
fees, lead times, service targets, tax/invoice policies or return windows. Expose configurable
fields where needed; require real values and owner assignment before opening to customers.

Questions about every supplier/reference price difference remain deferred. Proposed rule:
retain supplier facts; references help identity/unit interpretation and never silently
replace supplier prices. Put unresolved identity, conflicting attributes or price roles into
item-scoped review. This remains a proposal to review with the complete workflow, not a confirmed decision.

## Proposed ownership and integration boundaries

The following is a proposed ownership model constrained by the confirmed bidirectional
Sheets editing requirement (D-029/D-034); owner resolution is field-specific.

| Record | Proposed authoritative writer | Other surfaces and controls |
|---|---|---|
| Source message/media/receipt | Server durable intake | Sheets/operator source context; stable platform/source/revision keys |
| Owned purchase batches, quantities, reservations and movements | One server inventory ledger (proposal) | Woo availability projection; Sheets review/reporting; physical receipts and corrections audited |
| Supplier identity and offer history | Server ingestion/review | Sheets projection with supported direct manual edits captured, validated and synchronized back as auditable changes |
| Reference snapshots/matches/overrides | Server reference service | ReferencePrices projection and private WP decision queue |
| Product identity, selected supplier and release version | Server catalog service | Woo product projection with stable IDs and approved overrides |
| Basket price settings/quote | One server pricing contract, equivalent in MolaviShop | Existing WordPress/Woo basket behavior and Sheets controls reconciled, not independent formulas |
| Customer order and fulfillment | WooCommerce CRUD APIs/HPOS-compatible storage | Server/Sheets mirrors and operator tasks linked to Woo order ID |
| Reference and processing exceptions | Server queue | Private WordPress actions authenticated by current WP identity |
| Order tasks/notes/dispatch/aftercare | WooCommerce plus minimal MolaviShop extension | Existing admin/order screens; Sheets reporting; no competing order master |

Document fields each side may edit and conflict resolution. The older PHP shop/order path
must be explicitly redirected, retired or retained read-only as appropriate after backup
and parity validation. Do not leave two active customer order paths by accident.

## Bidirectional Sheets editing — confirmed requirement

Changes made by the server and supported manual changes made in Sheets must both
propagate. D-034 confirms every normalized Products data column is editable; source
IDs, original posts, timestamps and import/offer provenance remain protected.
Products is the operational row read by the server; Offers/MessageData retain
supplier evidence. On supplier updates the local adapter compares source baseline,
new server value and current Sheet value. It writes an untouched field, preserves a
Sheet-only correction, or holds both divergent values for owner review. A server
update must not silently erase a Sheet edit.

Confirmed D-030 conflict path: detect divergent changes since the last synchronized
version, preserve both values, flag the affected row/task for user review and wait
for an authorized resolution. The local private Woo dashboard records a choice and
note; open conflicts hold the product from quotes and publication. Do not silently
use “last writer wins”. Sheets APIs do not provide an atomic cross-system
transaction; local writes reread current cells and record detected races.

Accept on connected staging: edit a supplier package count in Sheets and see the
validated correction reflected in server/pricing/Woo; update the server and see
Sheets reflect it; simultaneous edits surface a conflict without losing either;
retries/outage, row reorder, duplicate messages and unsupported edits remain visible
and recoverable. Local synthetic tests now cover every editable Products field,
the Sheet REST batch write, owner conflict choices and quote/Woo holds. Connected
staging must still verify actual Sheets edits, edits made without a supplier post,
freshness changes and the Woo publication cadence. The supervised worker polls
Products and Shop every 60 seconds by default (configurable from 30 to 3600) and
queues durable Woo publication; this is polling rather than a Sheets on-edit trigger.

## Whole-stream contract

| Handoff | Planned behavior and operator action | Acceptance/failure evidence |
|---|---|---|
| Supplier → durable intake | Authorized forwarded messages, photos/albums, supplier identity, source IDs/date/revision, receipt before acknowledgement | Duplicate/re-forward adds no duplicate work; crash/restart retains messages; late/split album handling specified |
| Reference acquisition → snapshot | Explicit Gemini model/key, bounded PDF, validated currency/carton/unit/retail hints, coverage review, atomic versioned activation | Malformed/truncated/empty/partial extraction never replaces active data; failed/stale state appears in WP; previous data retained |
| Source + reference → extracted offers | Preserve raw supplier amount, derive explicit unit/package fields, normalize identity and match compatible attributes with provenance | Multi-product post stays item-scoped; brand/variant/count conflicts and uncertain roles enter review; reference is not customer-price authority |
| Extraction → operator review | Original post, proposed values, reference/history context, correction/skip and one-off versus reusable rule | Two operators/stale clicks cannot overwrite each other; correcting tea cannot reprice rice; overrides invalidated when incompatible |
| Approved offer → pricing/release | One selected supplier's cost/provenance, explicit sellability and availability, current/max discount bands, versioned release | Thin/no-valid-reference cases held as needed; no double discount; supplier advertisement does not become verified owned stock |
| Release → Woo catalog/media | Idempotent product/variant updates, allowed manual overrides, separate catalog/media receipts, explicit holds/withdrawals | Timeout retry does not duplicate products; unavailable item is not reset to instock; missing media stays visible as pending |
| Customer discovery → quote | Search/filter, pack/unit/minimum, phone/desktop basket, repricing all affected lines, current/max savings, server totals | Add/remove/reached-target/error cases; progress not confused with minimum order/free shipping; stale quote cannot silently submit |
| Quote → durable request/order | Single chosen checkout path, required contact/address timing, stable submit key and customer receipt | Double-click/disconnect creates one order; missed notification cannot lose it; unauthorized/customer-forged totals rejected |
| Paid order → procurement and exceptions | Named owner, available stock/supplier procurement, shortages/substitutes and customer choice only for exceptions | Changed total/quantity/substitute needs approval; no routine post-cart confirmation |
| Checkout → verified payment/fulfillment | Verified payment record, procurement/packing responsibility, configured checkout delivery charge and dispatch proof | Payment unverified means no paid state; supplier failure/partial dispatch creates a controlled exception; no invented delivery guarantee |
| Dispatch → tracking/aftercare | Guest-safe status access, explicit updates, cancellation, missing/damaged/returned goods, refund approval/record | Customer sees only their order; issue stays assigned until resolved; refunds are not auto-sent without approved policy/action |
| Daily work → reconciliation/recovery | One WP operations view for new/waiting/overdue/failed work, owner/next action, intake-to-publication counts and order mirror discrepancies | Failed work remains visible; safe replay/backups restore consistently; notification attempts are distinct from delivery receipts |

## Consolidated fulfillment — confirmed direction, remaining design

Customer lines link to internal supplier collection tasks. The consolidation step
records expected versus received quantities, shortage/damage exceptions and packing
readiness. An order is ready to dispatch only when its agreed contents are accounted
for. A shortage is not marked fulfilled or silently substituted. Split customer
deliveries are not an assumed first-release feature.

Normal case: two suppliers' goods arrive, the operator checks them against the
customer's agreed order, packs and records handover to delivery. Failure case: one
supplier's line is missing; the order remains held for an explicit resolution rather
than being marked fully ready. Customer change/approval and payment rules remain
proposals until resolved in the complete plan.

Resolved by D-013/D-014: procurement is driven by customer requests, and supplier-fixed
purchase units can necessarily create surplus stock. Include owned-stock management in launch scope, not only
supplier offer tracking. Full checkout payment must be verified before supplier procurement (D-015/D-020).

## Demand-led purchasing, owned stock and clearance

Confirmed intent: purchase customer-requested goods in the supplier's fixed selling
units where required, then manage and sell the remainder as surplus stock. Use age/expiry-aware discounts relative to current
selling prices. The following operating rules are recommendations awaiting review:

1. Check eligible, unreserved owned stock when preparing an order; reserve it and
   procure only the shortfall. Prefer batches expiring sooner when suitable. Supplier
   advertised availability is not an owned-stock balance. Reservation is released on
   cancellation/expiry of a request under the final agreed reservation policy.
2. Read supplier selling terms from forwarded posts and preserve them in the connected
   records/Sheets: selling unit, sellable units per supplier pack, explicit minimum
   purchase/multiple, package price, source and certainty. Existing Packaging, Package
   Price and Min Qty fields must be semantically mapped; do not reuse customer minimum
   as the supplier constraint or treat a product's set-count as a carton count.
   Group unmet demand by compatible product/variant and selected supplier offer.
   For an explicitly fixed pack of P units, shortfall=max(0, required-eligible
   unreserved stock), packs=ceil(shortfall/P), purchased units=packs*P. Apply other
   stated supplier minimum/multiple constraints where relevant; not every supplier
   has the same rule. Zero shortfall means no procurement. Unknown constraints enter
   review. Show required packs, payable package cost, customer allocations and surplus.
   Supplier pack rounding is necessary procurement, not a proposed optional bulk buy.
   Discretionary extra packs beyond this are a separate policy, not launch automation.
3. Receive each purchase as a batch with product/variant, supplier, package/unit
   conversion, quantities, purchase cost, receipt date and expiry/best-before meaning
   as applicable. Record expected/received/short/damaged amounts separately. Preserve
   each batch's cost rather than substituting today's supplier cost.
4. Track available/reserved/held/sold/returned/written-off movements with order links
   and audit events. Damaged, expired or otherwise held goods cannot be allocated for
   customer sale. Missing expiry data requiring review must not invent a date.
5. Clearance review considers time held AND usable time remaining; raw age alone can
   misclassify long-life versus short-life goods. Rules differ by product/category
   and batch, with configurable thresholds. No exact percentages/days are approved.
6. Define the comparison price as the current selling price of the same product, unit
   and condition at quote time; distinguish it from supplier cost, reference extraction
   prices and consumer list-price fields. Record the comparison-price timestamp.
7. Calculate a single final line price and savings without blindly adding clearance
   and basket percentages. Recommended initial policy: best eligible price, subject
   to an agreed margin/loss-approval rule. Customer must see remaining shelf-life/
   clearance terms before accepting a batch-specific offer; do not silently allocate
   materially shorter-life stock at a standard offer. Neither stacking policy nor
   below-cost clearance is confirmed yet.
8. Show stock quantity/age/expiry, reserved units, clearance suggestions, purchasing
   proposals and write-off tasks in the private WordPress operations surface. Woo
   receives only sellable availability and the agreed public price/expiry information;
   purchase costs and buying terms stay internal.

Normal replay: six requested units, no usable stock and a supplier selling only
twelve-unit cartons require one carton. After receiving/checking it, six units are
allocated to the customer and six become unreserved stock. With two usable units
already available, four additional units are needed, one twelve-unit carton is bought,
and eight newly received units remain unallocated after fulfillment. Customer order
quantities/minimums remain separate from the supplier purchase pack.

Failure replay: the fixed pack would create unacceptable expiry/cash/storage exposure.
Flag the purchasing task for an explicit resolution (another eligible offer, group
demand, hold or decline the request); do not invent a smaller negotiable pack or
silently add extra units to the customer's order. Shortage, cancellation, damage and
expiry holds each reconcile stock and customer tasks.

This design requires one stock ledger and reservation owner under E1, batch-aware
pricing/publication under E3, expiry/stock tasks under E4, allocation and returns under
E5, and stock/expiry/price/recovery cases under E6. It does not expand launch into
direct supplier delivery or a second inventory master.

## Tehran delivery operation — fixed fee and free-delivery threshold confirmed

Serve Tehran initially, using delivery arranged by the business owner. Keep one
consolidation/packing handoff before customer dispatch; do not require a courier
marketplace integration for launch. Native order address, internal notes and a
minimal dispatch task/proof can support the manual delivery arrangement.

Check the address against a defined launch boundary before final confirmation.
Tehran does not automatically include all surrounding cities or the province.
Show the agreed delivery charge and any agreed scheduling terms in the quote
before full payment. Do not invent an arrival promise or treat basket-discount
progress as a delivery-fee threshold. Packing owner and actual carrier/person are
operational assignments; owner-arranged does not mean the owner personally drives.

Confirmed D-022 supersedes manual per-order quotation: use a fixed customer delivery
fee of 250,000 toman (D-023), and delivery is free
when the cart reaches a fixed threshold. D-019 supplies a rounded initial planning threshold of 5,000,000 toman and the
agreed goods-subtotal basis. Keep actual incurred delivery cost separate from the customer's charge for
internal reconciliation. Do not expose purchase costs/margins to the customer.

Qualifying metric: goods subtotal after product/basket discounts, excluding
delivery. Initial fixed threshold: >=5,000,000 toman. This is separate from the reference-value
progress used for product discounts; the existing target is not the delivery threshold.
At qualification, delivery saving equals the otherwise quoted customer delivery
charge, and delivery payable is zero. The business still incurs delivery cost.
Below threshold, apply the confirmed fixed delivery fee of 250,000 toman.
No gradual below-threshold discount is selected. No gradual below-threshold bands/caps have been approved. The 5,000,000-toman
initial value derives from the tuna-pack benchmark and was accepted in the user's
follow-up. Keep it configurable for future explicit changes. Scope follows the agreed Tehran delivery area;
new oversized-load or geographic exclusions must not be invented silently.

Benchmark evidence: live public catalog on 2026-10-26 shows tuna customer-price
listings of 185,000–255,000 toman, with 210,000 as a middle example. Multiplying
210,000 by 24 gives 5,040,000; round to 5,000,000. This is an initial fixed
configuration, not automatic daily indexing to a specific SKU. Product unit semantics
remain subject to catalog verification; this is a practical planning estimate.

Suggested calculation:
customer delivery payable = 0 if qualifying goods subtotal >= 5,000,000 toman; otherwise 250,000 toman
final payable = discounted goods subtotal + customer delivery payable
These are separate from product reference-value progress and product savings.
Store the free-delivery threshold, qualifying value, rule version and price components
with the final quote/order. Display
goods subtotal, original delivery charge, delivery saving, delivery payable and
total clearly, without applying either discount twice. No gateway choice is needed.

Basket revisions before payment recompute both product and delivery discounts and
require renewed customer approval. Changes after payment require an explicit agreed
change/refund workflow; do not silently debit a difference. Handling a later actual
delivery-cost difference remains a policy to settle. Customer tracking/refund views
must preserve the accepted delivery/subsidy breakdown rather than use today's rules.

E3 must define the shared rule; E4 the customer discount/progress explanation and
operator quote input; E5 the accepted breakdown and change-handling; E6 boundary,
basket-change, missing-delivery-quote and no-negative-delivery tests.

## Direct checkout, price validity and procurement funding

D-020 supersedes routine request-first/manual quote confirmation. Customer flow:
choose products → cart/address/checkout with final prices and delivery payable →
pay the complete displayed amount → verified payment → supplier procurement or
owned-stock allocation → consolidation/packing → delivery. Supplier purchasing pack
rounding remains internal; it must not increase customer quantity or charge silently.

One coherent quote is calculated server-side for the selected supplier/stock source,
product discounts, clearance and delivery/free-delivery qualification. Freeze a
versioned quote/amount for the payment session; snapshot the accepted order prices,
not today's future catalog. No routine operator/customer reconfirmation step.
If price/eligibility changes before payment initiation, show updated basket totals
or the held item explicitly; never debit a silently changed amount. Payment callback
and duplicate retries must create/settle one order. A source changing after payment
is an operator exception, not permission to charge more or remove paid lines silently.

### Supplier price-validity controls

Confirmed D-021: use elapsed time since supplier price was last verified. Warn at
>=7 days (168 hours), and make the offer unavailable for new purchases at >=14 days
(336 hours). These are initial editable settings, independent of the reference
snapshot's 24-hour policy. A shared default applies; per-supplier/category overrides
can be supported without silently inventing different initial limits.

| State | Proposed behavior |
|---|---|
| Current | Offer can be sold if all other eligibility/stock checks pass |
| Warning: >=7 days and <14 days | Persistent dashboard task/notice; refresh or verify price; not a routine extra customer checkout step |
| Blocked: >=14 days | Exclude offer from purchasable catalog/quotes and reject new payment initiation using it; give a clear customer-facing unavailability reason |
| Verified fresh again | Clear price-staleness gate with provenance; keep any separate stock/review/damage hold |

Measure source evidence, not just time the import job ran. Repeated old posts, empty
refreshes and failures must not renew validity. Manual verification records actor/time.
Enforce at the server even if WordPress/cache/browser shows old data. Compare large
price changes/identity conflicts in a separate validation policy rather than allowing
any fresh timestamp to bypass review. Never treat a supplier offer as owned stock.
Owned, eligible stock and another valid supplier offer may remain sellable under
source-aware rules; do not automatically withdraw the entire product because one
offer expired. Exact alternate-offer/owned-stock price authority remains in E1.

### Checkout prerequisites and remaining business inputs

- Delivery >=5,000,000-toman qualifying subtotal is free; below it, D-022
  selects one fixed Tehran delivery fee; D-023 sets it initially to 250,000 toman. This resolves the
  earlier manual delivery-quotation conflict with direct checkout. No area-based
  calculation is required at launch. The actual owner delivery cost remains internal.
- Guest name/mobile/address with optional account/email is confirmed by D-025. Use
  standard Woo checkout identity/contact mechanisms where possible; tracking must
  protect customer data and recovery must not assume an SMS service.
- The installed payment integration must be checked, not assumed operational from
  activation alone. Validate sandbox, IRT/IRR conversion, amounts, callbacks, retries
  and failure/cancellation paths. No live charges during plan execution/testing.
- Payment verification precedes supplier purchase. If buying full supplier packs
  requires more cash than customer payments cover, show the surplus cash exposure
  as an internal purchasing task. Do not bill the extra units to the customer.
- Refunds, supplier failure after payment, quote/payment-session expiry, reservations
  and partial fulfillment need explicit handling; no arbitrary fees/promises.

### Order/task lifecycle proposal

Use native WooCommerce order/payment semantics plus minimal operational fields:
checkout/payment pending → payment verified → procurement/stock allocation →
consolidation/packing → dispatched → delivered/closed. Exceptions include payment
failure, supplier shortage, on hold, cancellation and aftercare/return/refund.
One assigned owner, next action, last event and any due time accompany each active
order/task. Do not invent an SLA or equate Woo completed with delivery automatically.

## Paid-order shortage and replacement proposal — confirmed workflow

User accepted D-024. Role: order operator. Sample: customer has paid for rice and
detergent; rice is available, but the detergent supplier cannot fulfill.

1. Check eligible owned stock and other suppliers for the same item at the paid
   customer price. Flag owner approval before buying at a loss. Do not charge the
   customer extra without their explicit approval.
2. If the same item cannot be supplied, propose a replacement or let the customer
   choose a partial refund for that line, or cancellation/refund of the whole order.
   Hold dispatch until the choice is made, with an owner and next action.
3. If removal drops the cart below the free-delivery threshold, retain the original
   delivery charge agreed at checkout. Calculate the refund from the paid order
   snapshot and do not silently add a new delivery charge.

User clarified that the system should suggest substitutes for an unavailable
ordered product. Generate a shortlist from eligible available products, with
product type, brand/size/packaging, customer quantity and price visible. Prefer
compatible products and make availability evidence clear; owned stock and valid
supplier offers are candidate sources. These matching/ranking details are proposals,
not yet confirmed rules. Reuse an existing replacement mechanism if it exists and
is appropriate; its implementation and deployed use remain unverified.

Show the paid original price and proposed revised order total; the customer must
explicitly choose a substitute. Do not silently alter the paid order or charge a
difference. Capture accepted order revision, settlement/refund status and linked
operator task. A decline/no-response remains an assigned follow-up, with the existing
partial/full refund choices available. The original delivery charge remains protected
under D-024.

Difference-in-price settlement, actual customer-contact channel and refund method/
timing remain open. Existing installed payment integration must be assessed before
choosing a refund workflow. No real messages, payments or refunds are part of planning.

## Checkout identity — confirmed direction

D-025: allow guest checkout with name, mobile and Tehran delivery address; account
creation and email are optional. Use existing WooCommerce checkout/order APIs; no
routine manual quote or order-confirmation step. Small retailers can use optional
accounts for repeat ordering.

Before payment the server checks actual cart totals, delivery threshold, eligible
supplier-price age and availability, then stores the accepted order/payment amount.
A guest gets a secure way to view order status and choose a replacement, without
exposing personal data through a sequential order ID. The specific recovery/
verification method and communication channel remain to be designed; an SMS or
automatic-message integration is not assumed. A closed browser after payment must
not create another order or lose the verified payment callback.

## Batch-based clearance pricing — user direction and pending formula

D-026: the clearance price should consider the date the batch was bought, its
production date where available, expiry date, purchase cost, and the item's current
market price, confirmed in D-027. This is a price-generation requirement, not an approved fixed markdown.

Proposed calculation architecture: preserve purchase date/cost per lot; calculate
time in stock and remaining time to expiry at the current date. When production date
is known, also calculate the elapsed share of the production-to-expiry shelf life;
when absent, do not fabricate a production date or pretend purchase date is one.
Compare only equivalent product, quantity/unit and market observation, with its
source/time; do not silently use the extraction-reference anchor as market selling
price. A batch with missing expiry or stale/missing market observation enters an
explicit review/fallback path. Do not sell expired/held units.

The eventual formula must explain how dates, batch cost and market observation affect
the suggested price; support operator review and configurable rules, and surface
likely loss/near-expiry stock without assuming authority to sell below cost. Preserve
the basis with the price. If multiple lots qualify, handle quantities, expiry and
allocation explicitly so a customer accepts relevant shelf-life information.

### Integration with the normal-period basket formula

D-028: the existing normal-period basket formula remains the starting point. For
an eligible clearance batch, the final unit price must be lower than its normal
price under the same product and basket conditions, producing a larger discount.
The amount of the additional clearance adjustment must consider the confirmed
batch dates, purchase cost and current market price (D-026/D-027). This is not a
choice between two independent fixed prices.

Proposed calculation structure, not an approved numeric formula: (1) compute
the normal quote using the existing basket calculation; (2) compute a batch-aware
clearance adjustment from the approved factors; (3) derive a single final clearance
unit price; (4) show current/maximum discount and totals consistent with that price
and the appropriate product reference price. Recalculate if basket composition
changes, as existing basket rules require. Only quantities allocated to the eligible
batch receive its clearance treatment. Show relevant expiry/condition before purchase.

Illustration only: if the normal price for this basket is 90,000 toman, a calculated
clearance price might be 85,000. The total saving must be represented once, not
deducted twice. The goods subtotal used for the free-delivery threshold follows the
final product prices. The final agreed price is snapshotted for the paid order.

D-031 confirms an early notification as the calculated clearance price approaches
the batch purchase price; owner approval is required before publishing a below-cost
clearance price. The warning must occur before reaching cost where possible.
Remaining decisions: actual formula/weights, proximity-alert threshold and timing,
market source/freshness, missing dates and exact notification channel/recurrence.
Do not label purchase-cost recovery as full profit or invent numeric bands.

## Clearance calculation proposal and remaining project handoffs

See `clearance-pricing-design.md` for a concrete reviewable batch-pricing model,
inputs, edge cases and a synthetic worked example. It retains the existing normal
basket formula and creates one lower clearance price; timing, permitted floor,
market source and interpolation are still proposals, not approved settings.

The whole-plan ownership recommendation remains server-managed offer/stock/pricing
records, WooCommerce customer orders/fulfillment and connected Sheets review/reporting.
Fulfillment, cancellation/returns, secure guest tracking and operator recovery remain
required handoffs; complete their contracts alongside reference ingestion and release
preparation, rather than treating the clearance component as the whole project.

## Consolidated remaining operations proposal

See `operations-and-ownership-plan.md` for the complete order/stock/fulfillment/
aftercare lifecycle, dashboard, record-owner proposal, reconciliation and remaining
business choices. This is a reviewable proposal; it does not silently establish
Sheets versus server as the authoritative stock/pricing writer.

## Coordinated implementation sequence

These are work packages within one execution, not repeated requests for permission.

| Package | Work and completion gate |
|---|---|
| E1 — Freeze contracts and migration map | Resolve the grouped business choices; assign record writers, source/product/order IDs, edit rights, statuses and release settings. Map all required GAS behaviors using existing 176-function inventory: port, replace by native feature, archive debug/maintenance, or explicitly defer. Treat A/B audit findings as acceptance inputs. |
| E2 — Connect ingestion, reference and review | The local implementation now covers durable intake/source revisions/albums, reference acquisition and a crash-aware daily refresh, matching/history/category inference, owner AI confirmation, reusable overrides and item-scoped review in WordPress. Next validate configured owner access, live PDF/Gemini/Sheets calls and failure recovery in connected staging. No hidden GAS runtime fallback. |
| E3 — Complete catalog/pricing/publication | Reconcile data owners and Settings/Shop controls; preserve basket discounts; add purchase-batch stock/reservations and expiry clearance; fix supplier/cost and stock/media receipts; introduce versioned releases, overrides, withdrawal and retry. Preserve Woo IDs/URLs/media and agreed manual edits. Batch clearance calculation and its near-cost alert need the recorded rule/threshold decisions before customer pricing can be enabled. |
| E4 — Complete customer + operator interfaces | Produce coherent storefront and private operations mockups using existing Elementor/Woo/MolaviShop. Implement discovery, basket, chosen checkout, reference/product review and unified work queue. Native roles/capabilities/nonces, translated text and mobile/RTL/accessibility. |
| E5 — Connect order-to-aftercare | Durable authoritative order receipt, idempotent mirror/outbox, classic/block coverage, reuse/verify existing replacement proposals and customer choice for shortages, supplier exception coordination, checkout price snapshot, verified payment, preparation, dispatch, tracking, cancellation/returns/refund tasks. Reuse Woo APIs/Action Scheduler. |
| E6 — Validate as one system | Connected staging replay through every handoff, failure/restart/duplicate and recovery tests; consistency checks across server/Sheets/Woo; household roleplay then retailer; phone/desktop UI review and mockup approval. Fix discovered gaps within the same execution. |
| E7 — Prepare release and cutover | Versioned code/config/backup bundle, tested migrations, rollback steps, operator runbook and monitoring. Present concrete release and evidence for the user's explicit production instruction; then deploy in dependency order, verify and retire old jobs only after parity/data reconciliation. |

Implement technical details without reopening micro-policy questions. If a new issue changes
business responsibility, payment/stock promises, price authority or irreversible data handling,
resolve it in the plan rather than silently inventing policy. Keep unsupported features explicit.

## Existing work to reuse

- Immutable GAS export, original/hash provenance, inventories, historical and recovered audits.
- Mockup v2 source, existing Woo/Elementor widgets and basket-pricing tests. Draft is not approved.
- Reference acquisition/store/owner-decision gate/private dashboard prototypes.
- Durable intake/batch/publish/order/notification jobs and Woo written-receipt/scheduled retry.
- Current evidence: 235 offline Python tests and 93 isolated WP/Woo assertions pass.
  These are building blocks, not completed E1–E7 or a full live end-to-end pass.
- Read-only live server, bot, storefront and plugin checks; credentials already provided.
  Verify deployed model/settings/jobs when needed; do not ask for another archive/upload
  or presumed external refresh service. Inventory actual secrets by presence/readiness only.

Known prototype work: opt-in persistent storage/worker and owner bridge are not installed
on the service; connected customer fulfillment/recovery remains open. Local reference
history/category fallback, AI confirmation, scheduled PDF/Gemini candidate staging and
owner-reviewed `ReferencePrices` projection are implemented, but live credentials, Google
calls and connected refresh are unverified. The paid-order mirror connects to audited
stock-lot receipts, FEFO reservations, fixed-pack shortfall proposals and owner-managed
follow-up in the Woo dashboard; lot/workflow data stays in SQLite and supplier purchases
are never submitted automatically. Clearance pricing and its alert thresholds remain a
later business-rule decision; no below-cost price is published automatically.

## Validation and completion criteria

Acceptance uses actual connected staging components with synthetic business data, not only
mocks of every handoff. Combine existing targeted tests with new cross-boundary tests;
do not repeatedly rerun broad historical audits for unchanged code.

1. Household normal path: forwarded multi-item offer → reference/review → released catalog →
   phone basket → direct checkout with price/stock gates → verified payment → procurement →
   packing/dispatch/delivery → closed order with consistent records and receipts.
2. Retailer path: larger quantities, package minimums, multiple suppliers, repeat order,
   changed prices and any agreed invoice requirement, desktop as well as phone.
3. Failure path: stale reference/failed AI, ambiguous two-product price, late photo, supplier
   out-of-stock, partial publication, double submit, disconnect after commit, changed quote,
   missed notification, unavailable supplier, cancelled/returned item and missed follow-up.
4. Recovery path: restart during write/publication, repeated source/order delivery, conflicting
   operator action, backup restore and old/new job coexistence. No lost or falsely completed work.

Done means: every handoff has an authoritative record and observable receipt/state; every
active item has an owner/next action; all required GAS behaviors have a disposition; source,
stock, pricing and order data reconcile; no known blocking A/B finding remains; roleplay and
UI acceptance pass; operations/rollback work; production was only changed if explicitly instructed.
Evidence must name code/version, config schema, fixture data, plugin versions, checks, failed
or waived cases and scope of live/staging verification. Do not replace this with a test count.

## Release and operation requirements

Inventory the currently active WordPress, WooCommerce, MolaviShop, Elementor, payment,
security and cache plugins/settings; reuse their appropriate capabilities through supported APIs.
Verify
HPOS, classic/block checkout, RTL/mobile and cache invalidation. Keep customer/order work
private, cost/reference tokens internal, and capabilities least-privilege. Verify webhook
submitter authentication and authorized supplier/operator identities as part of the contract.

Server needs persistent private storage, supervised workers/scheduler, secret configuration,
TLS, backups/restore, migrations, job health and operator-visible failure/retry controls.
Use timezone-aware schedules and business display in Asia/Tehran. Verify active GAS jobs
before cutover; do not copy an unverified trigger or disable it prematurely. Feature-gated
staging rollout, reconciliation, backup and an explicit rollback point precede retirement.

Prepare customer/operator message templates during build; do not send real messages, submit
orders, arrange payments, activate plugins or publish designs during planning/testing. No
new gateway, delivery promise, service territory, tax calculation or refund policy is invented.

## Review bundle and next step

The final review bundle includes the full workflow/data/state map, traceable migration
matrix, responsive storefront/operator mockups, tested source/PR, staging replay evidence,
configuration template without secrets, operator runbook and cutover/rollback checklist.

The user has now confirmed that all normalized Products data columns are editable and
that field conflicts wait for their choice. The next step is connected staging of direct
Sheet edits through freshness checks, the server quote, and the existing Woo publication
path. Continue the remaining integrated workflow after that check; keep production
activation behind the existing explicit release boundary.
