# MolaviSale decision log

Revision 4.0. Updated 2026-10-25, Asia/Tehran. This is a business/design decision log,
not an infrastructure access log.

## Explicit user requirements

| ID | Requirement | Evidence/status |
|---|---|---|
| U-01 | Clean, crisp, professional, minimal; customers understand the next action intuitively | Explicit user direction |
| U-02 | Mockups first, before redesigning the live storefront | Explicit user correction |
| U-03 | Show each product's maximum discount | Explicit v1 feedback |
| U-04 | Show current discount based on basket size, distinct from maximum | Explicit v1 feedback |
| U-05 | Provide a phone version | Explicit v1 feedback |
| U-06 | Make the palette more vibrant | Explicit v1 feedback |
| U-07 | Read project Markdown and keep documentation proper | Explicit user direction |
| U-08 | Payment solutions in Iran are a future task; prioritize a fast, hassle-free start | Explicit user answer |
| U-09 | Plan the whole roleplay and make it accessible from other chats | Current explicit user request |
| U-10 | Cover both buyer types, starting with a household buying groceries in bulk, then a small shop | Latest explicit user selection |
| U-11 | Roleplay the entire operating stream: data entry, processing, site publication and efficient UI, receiving orders, and keeping up with them | Explicit scope clarification; storefront-only planning is insufficient |
| U-12 | Intended intake is forwarded bot messages analyzed into Sheets, assisted by a separate known-wholesaler reference source for extraction | Confirmed requirement; GAS source recovered, active upstream configuration and field authority open |
| U-13 | Locate the existing `ReferencePrices` process and audit the GAS-to-bot transition for lost behavior and remaining transition logic | Explicit user request; audit required before migration sign-off |

## Working proposals: not yet confirmed business policy

| ID | Proposal | Resolve in |
|---|---|---|
| P-01 | Guest request using name/mobile; no mandatory account or email | WF-06 |
| P-02 | Confirm availability, final quote, and delivery before arranging payment | WF-07/08 |
| P-03 | Orange actions, raspberry maximum discount, teal current savings, violet progress | WF-05/11 visual review |
| P-04 | Desktop sidebar basket; phone fixed basket control opening a bottom sheet | WF-05/11 usability review |

## Open business decisions

| ID | Decision | Status / next scene |
|---|---|---|
| O-01 | First roleplay persona | Resolved: household first, then small shop; cover both / D-001 |
| O-02 | Seller/coordinator role and first delivery area | Open / WF-07/08 |
| O-03 | Supplier verification, publication authority, offer freshness | Open / WF-00/02 |
| O-04 | Minimums and discount explanations understandable to buyers | Code-backed behavior; validate / WF-05 |
| O-05 | Request fields, identity check, receipt, and operator notification | Open / WF-06 |
| O-06 | Price-lock timing, quote validity, substitutions, partial stock | Open / WF-07 |
| O-07 | Manual payment handoff and delivery responsibility/cost | Open / WF-08 |
| O-08 | Tracking, cancellation, returns, customer support | Open / WF-09 |
| O-09 | Iranian payment-provider selection/integration | Deferred task |

## Scene decisions

Persona selection is recorded; no full scene has been completed. The active sequence
is now WF-00…WF-11 in `workflow-roleplay.md`.

### D-001 — First customer persona

- Scene: WF-00.
- Chosen behavior: cover both customer types, starting with a household buying groceries
  in bulk, then a small shop buying stock.
- Status: explicitly confirmed by the user, 2026-10-25.
- Design implication: explain units, minimum quantities, and combined-basket discounts
  in everyday customer language; prioritize the phone shopping journey.
- Boundary: the latest answer supersedes the earlier household-only answer; do not drop
  the small-shop replay from the plan.
- Next dependency: determine who sells/fulfills the basket and the first launch promise.

### D-002 — Whole operating-stream scope

- Chosen scope: supplier intake, extraction, normalization, review, pricing, publication,
  customer UI, durable order receipt, operator management, fulfillment, and follow-up.
- Status: explicitly clarified by the user.
- Implication: design both the customer experience and operator workflow, including
  authoritative records, automation boundaries, exceptions, retries, and reconciliation.
- Sequence: start at WF-00 (current data entry), not with a website redesign or checkout alone.

### D-003 — Two input streams: forwarded offers and extraction reference

- Scene: WF-00, continuing into WF-01/02.
- User description: messages forwarded to the bot are analyzed and saved to Sheets;
  another source reads a well-known wholesaler as an anchor to help proper extraction.
- Status: confirmed as the intended model, not independently verified as fully implemented.
- Keep the actual supplier offer and the anchor/reference data distinguishable, including
  source identity and timestamps. How the anchor can influence fields is still to be decided.
- Code observation: this checkout has channel-specific extraction rules and review checks;
  the read/search performed did not identify a separate external anchor-reader connection.
  Do not equate named-channel parsers with the independently described reference source.
- Updated source evidence: the user's archive contains the reference logic, a legacy
  Rezvan Pakhsh PDF importer and a main refresh delegating to a separate server. Do not
  repeat the initial identity question; recover the actual upstream service/configuration.
- Later questions: fields it assists, matching rules, refresh/freshness, missing matches,
  conflicts, and whether any inferred value needs operator approval. None is settled yet.

### D-004 — Existing ReferencePrices process; migration audit required

- User clarification: the anchor process writes to `ReferencePrices` and should already
  be in place; older GAS iterations must be inspected to recover it.
- Status: user-confirmed existing-process requirement; implementation provenance unverified.
- Audit performed: all fetched branch histories (66 commits, 12 GAS file revisions),
  eight GAS/Python extraction comparisons, 64 existing tests, operational reproductions,
  and inventory of 67 named GAS functions. See `gas-migration-audit.md`.
- Finding: current Python pipeline does not consume the seeded reference sheet. Its actual
  producer/consumer source was not found in inspected Git history; configured GAS read-back
  returned 404. This is a source gap, not proof that the user's process is absent.
- Updated by D-005: GAS source recovered; upstream refresh-server source and actual trigger/
  deployment bindings remain open. Do not invent an anchor replacement, silently retire
  GAS, or claim the migration is complete.

### D-005 — User-supplied GAS source recovered; evidence checkpoint

- Input: user uploaded `gas.rar`; later asked to finish the interrupted work.
- Status: source receipt and technical audit evidence, **not** approval of new business
  policy, architecture, live deployment or production fixes.
- Recovery: eight unchanged files / 176 named functions, hash manifest and local source
  snapshot. The files did not exactly match any previously fetched Git blob. The user
  subsequently authorized committing/pushing the snapshot for future-chat access.
- Recovered contracts: ReferencePrices, AI batch extraction, weighted matching, category/
  history price-role inference, operator/reference confirmations, Inventory log and a
  fixed-markup storefront sheet. Current Python has no equivalent reference/AI integration.
- Validation: 18 isolated offline observation groups, including stale/conflicting reference
  use, multi-product confirmation scope and selected-supplier/cost inconsistencies.
- Next dependencies: upstream refresh-server source/configuration metadata, manifest,
  installed triggers and deployed writer ownership. Preserve useful features and explicitly
  resolve competing inventory/pricing contracts before implementation or GAS retirement.
- Continuity: use `recovered-gas-audit.md`; no scene has been completed or mockup approved.

### D-006 — Repository preservation and economical inspection

- User instruction: push the supplied GAS code as a source of truth and take a
  considerate path because file sharing appears to consume the usage allowance.
- Chosen scope: preserve the unchanged eight-file export, hash manifest, audit evidence,
  inventory and continuity documentation in the project repository. This does not
  make the snapshot a verified live deployment or approve production logic changes.
- Future work: start with the source guide/inventory, inspect targeted code ranges,
  reuse evidence for unchanged code and keep tool output concise. Do not ask for the
  archive again when repository access is available; do not promise a usage-cap amount.

Add subsequent records using this template:

```text
Decision ID / scene:
Situation:
Chosen behavior:
Status: confirmed by user / proposal / unresolved
Reason:
Customer screen/message:
Operator or supplier action:
Acceptance criterion:
Exceptions / remaining dependency:
```

Do not record suggested options, silence, or previously inferred preferences as confirmed answers.

### D-007 — Server owns the migrated GAS functionality

- User reconfirmation: the goal is to move GAS functionality onto the server, not return
  to GAS as the operating architecture.
- Status: confirmed direction. The recovered export remains the reference for preservation,
  comparisons and audit; it is not a selected ongoing runtime or fallback.
- Scope: migrate required reference acquisition/matching, extraction, review/confirmation,
  records, schedules and processing behavior, with explicit disposition of old helpers
  and defects. This does not approve every legacy rule or establish completed migration.
- Configuration: existing GAS properties are migration inputs. Transfer required settings
  to server environment/secret storage; never commit secret values. A server environment
  variable and a GAS Script Property are separate settings.
- Existing refresh service source/location and active bindings remain unresolved. Track
  those dependencies while progressing with independent workflow/design work. Do not
  repeatedly reopen the server-versus-GAS execution-target decision.

### D-008 — Reference path clarification; external helper is conditional

- User clarification: if the missing endpoint is not Rezvan Pakhsh itself, the process
  they used was Google API inference for reference prices.
- Source evidence: refreshReferencePricesFromPdfLegacy reads Rezvan Pakhsh's PDF;
  refreshReferencePricesFromPdf and callGeminiWithInlinePdf use Google's Gemini API,
  AI_API_KEY and AI_MODEL, then write ReferencePrices. This implementation is recovered.
- Boundary: active execution/settings still need verification. The separate
  REFERENCE_REFRESH_SERVER_URL wrapper exists in source but its configured use and a
  separate running service have never been established. Declared code is not live proof.
- Historical correction: treating an external refresh server's location/source as a
  mandatory prerequisite was too strong. The later direct user clarification in D-036
  supersedes the PDF/Gemini source assumption below.
- Target remains server execution under D-007. Keep required settings/secrets in server
  configuration; preserve useful GAS behavior and address audited defects before retirement.

### D-009 — Owner chooses the reference failure path per occurrence

- Confirmed by user: whenever reference data is stale or a refresh fails, ask the
  business owner whether to pause/review or continue with the last successful
  snapshot and a warning. Wait for the decision before affected automatic reference
  interpretation. No response is not consent; no permanent fallback was selected.
- Bind choices to occurrence and snapshot; preserve an audit record. Local draft
  covers persistence, retries and conflicts; live pipeline integration is incomplete.

### D-010 — Reference age cutoff is 24 hours

- Confirmed by user: at 24 hours old, a successful reference snapshot requires the
  per-occurrence owner choice above. This is an age policy, not proof of source
  publication freshness or installation of a daily schedule.

### D-011 — Decisions belong in the WordPress dashboard

- Confirmed by user: use the private website operator dashboard, closely integrated
  with existing WordPress functionality/plugins. Extend MolaviShop/WooCommerce admin
  and native WordPress identity/capability/nonce mechanisms. No new public login or
  Telegram decision channel is selected.
- Read-only live plugin inventory is now verified; see reference-dashboard-integration.md.
  Draft PHP/runtime compatibility, owner-ID binding and server integration remain open.
  Production installation/activation and mockup approval are not authorized.

### D-012 — Consolidated delivery for the first release

- User confirmed consolidated delivery is sufficient for now. Plan supplier collection
  into a consolidation/packing step followed by coordinated customer delivery.
- Direct supplier-to-customer deliveries and hybrid fulfillment are outside the
  first-release path; do not build their workflows as launch requirements.
- The plan must track expected supplier lines, received/short/damaged quantities,
  packing completeness and dispatch. These are derived design requirements, not
  proof of an existing consolidation facility or a new stock/payment policy.
- Still open: whether goods are stocked in advance or procured per confirmed order,
  packing/collection/delivery owners, territory and cost rules. No warehouse address,
  delivery promise, seller-of-record or payment sequence was confirmed by this answer.

### D-013 — Demand-led buying with surplus owned stock and expiry-aware clearance

- User direction: buy what the customer requests, sometimes buying extra quantity
  to obtain a better purchase price. Surplus therefore requires owned-stock management.
- User wants clearance discounts relative to the product's current selling price
  when stock has remained long relative to its expiration date; suggestions invited.
- This supplements D-012 consolidated fulfillment. It does not choose stock-led
  speculative replenishment, authorize real purchases, or resolve procurement/payment timing.
- Required planning scope now includes purchase batches, cost/quantity, expiry and
  receipt dates, movements, order reservations, surplus availability and expiry-aware
  pricing. Supplier offers and physically received stock remain separate facts.
- Proposed, not confirmed: use available owned stock first (earliest-expiring eligible
  batch), then procure a shortfall; suggest bulk overbuy for owner approval; budget/
  storage/sell-through gates; one final pricing calculation that avoids blindly stacking
  clearance and basket discounts. No markdown percentages/time bands or loss authority
  have been approved. Do not substitute extraction-anchor prices for current retail prices.

### D-014 — Supplier-fixed purchase units drive procurement and surplus

- User clarified that most suppliers sell in fixed units/quantities that MolaviSale
  cannot choose freely. These terms are present in forwarded supplier posts and are
  supposed to be extracted and recorded in Sheets.
- Correction to the D-013 proposal: surplus is often a necessary consequence of
  supplier pack constraints, not an optional bulk-discount purchase. Withdraw the
  pending optional-overbuy approval question as the default first-release decision.
- Preserve supplier selling unit, count per purchase unit, explicit supplier minimum/
  multiple if stated, package price, source post and interpretation certainty. Keep
  these distinct from the customer's minimum quantity and the composition of one
  sellable product (for example a multipiece set).
- For a confirmed fixed-pack offer, calculate required packs from unmet demand and
  keep the unallocated received remainder as owned stock. Missing/ambiguous terms
  require review rather than invented carton counts or negotiable quantities.
- Existing source has Packaging, Package Price and Min Qty fields/helpers. This is
  evidence of some representation, not proof that explicit supplier purchase terms
  are correctly extracted, persisted and consumed end to end. Map semantics in plan.
- Procurement commitment/payment timing and discretionary purchases beyond required
  supplier packs remain separate open policies; no real buying is authorized.

### D-015 — Full confirmed customer payment before supplier procurement

- User selected payment of the full confirmed amount in response to the timing
  question: full payment, deposit, or pay later before purchasing from suppliers.
- Confirm the customer quote, then verify full payment before committing the
  supplier purchase. Request receipt or a claimed payment is not payment verification.
- Deposits and payment-on-delivery are not the first-release procurement flow.
- Payment method/provider, verification responsibility, quote validity and handling
  of later supplier failure/refund remain open. Gateway selection stays deferred.
- This answer does not approve all other checkout/account/record-owner proposals
  or authorize real collection of funds, procurement or production changes.

### D-016 — Tehran launch territory; owner-arranged delivery

- User confirmed Tehran as the initial service area and that they will arrange
  customer delivery themselves. Keep consolidated fulfillment under D-012.
- Do not infer a dedicated fleet, an external delivery integration, coverage of
  surrounding cities/province, free delivery, a fixed fee or a delivery-time promise.
- Capture and check delivery address before the final customer quote/payment.
  The exact Tehran coverage boundary, dispatch/packing personnel, charges and
  delivery scheduling remain to be specified as launch settings/policies.
- Next planning question: calculate the delivery charge for each order before
  payment, or use an owner-defined fixed/zone tariff. No price is invented.

### D-017 — Per-order delivery quote with an order-size delivery discount

- User accepted the explained per-order delivery quote approach and added a
  requirement to present a delivery discount based on order size.
- Keep base delivery charge, delivery saving and customer-payable delivery distinct
  from product/basket discounts. Include final delivery payable in the confirmed
  total before full customer payment under D-015.
- Not yet defined: whether size means monetary goods value, quantity or another
  measure; discount function, bands, caps and eligibility. No free-delivery promise
  or existing product-discount threshold is implied.
- Recommendation pending: use goods subtotal after product discounts, before delivery;
  configurable subsidy bands/cap. Display savings and final payable; record actual
  delivery cost separately for internal profit/reconciliation.

### D-018 — Free delivery at a fixed cart-size threshold

- User requests delivery free of charge once the cart reaches a fixed amount.
  This specifies the free-delivery rule under D-017; it does not approve invented
  monetary bands, percentages or an amount.
- Threshold value and measurement basis remain open. Recommendation: goods subtotal
  after product discounts, excluding delivery. Existing product-discount progress/
  target remains separate and is not automatically the delivery threshold.
- Below-threshold delivery treatment remains to be finalized; owner-quoted delivery
  is already confirmed, but no graduated discount below the threshold is approved.
- At qualification customer-payable delivery is zero; actual delivery cost remains
  internal MolaviSale fulfillment cost. Final accepted quote must record the rule
  version/threshold and charge/saving/payable components. No production pricing change.

### D-019 — Tuna-pack benchmark for initial free-delivery threshold

- User accepted the subtotal-basis recommendation and requested a rough rounded
  threshold comparable to a mid-range 24-can tuna pack, rather than specifying
  a numerical amount.
- Read-only live public Woo Store API on 2026-10-26 lists tuna offers at 185,000,
  210,000 and 255,000 toman (IRT). These are public customer prices, not supplier
  costs or extraction-reference prices. Product per-unit/pack semantics remain
  subject to the overall catalog audit; no purchasing data was inferred.
- Derived planning value: use the 210,000-toman listing as the middle benchmark;
  24 × 210,000 = 5,040,000, rounded to 5,000,000 toman. This is the assistant's
  concrete implementation of the user's approximate benchmark, not a user-typed
  exact amount. Keep configurable and visible in the plan/release review.
- Qualification basis: goods subtotal after product discounts, excluding delivery;
  threshold met at >=5,000,000 toman. Separate from the product-discount target.
- Fixed initial threshold: do not automatically index it to a tuna SKU/day-to-day
  price or promise a separate lower threshold. No production setting changed.

#### D-019 follow-up — rounded threshold accepted

- User replied “Ok. Lets go firth” after the concrete 5,000,000-toman threshold
  and after-product-discount/excluding-delivery basis were presented. This accepts
  that planning value. No production pricing change is authorized.

### D-020 — Direct checkout/payment with price-validity warning and blocking gates

- User rejects the proposed separate customer quote/order-confirmation step: with
  regularly refreshed prices, customers should complete the cart and proceed to
  payment as in a normal store. This supersedes request-first/manual final quote
  as the routine checkout proposal. Full verified payment before procurement (D-015)
  remains; account/email/contact policy is not resolved by this answer.
- User requires one price-validity threshold to start notifications and another to
  make the affected offer unavailable. Proposed interpretation is elapsed time
  since supplier price was last verified; metric and exact values need confirmation.
- Supplier-offer price validity is separate from D-010's 24-hour reference snapshot
  decision threshold. Do not reuse the reference or free-delivery threshold.
- Derived design: enforce eligibility in publication/cart/payment creation, record
  price/source/rule version, freeze payable amount for payment session and handle
  paid exceptions explicitly. Successful polling/re-import is not by itself verified
  new supplier price evidence. Do not retrospectively reprice a paid order.
- Remaining checkout blocker: below-threshold delivery must have a calculable fee
  before payment. Earlier manually confirmed per-order delivery quotes need a
  compatible automatic/address-based or preconfigured rule; no tariff is invented.
- Existing payment plugin readiness/sandbox/callback verification belongs in the
  execution plan. This answer does not choose a new provider, authorize real payment
  or remove the explicit production-change boundary.

### D-021 — Supplier-price warning at one week, purchase block at two weeks

- User confirmed elapsed time since supplier price was last verified as the metric
  and selected one week for warning, two weeks for unavailability, for now.
- Initial settings: warning at >=7 days (168 hours), blocking at >=14 days
  (336 hours). From day 7 until day 14 warn the operator; at day 14 block new
  purchases from that offer until valid fresh evidence/verification is recorded.
- Keep thresholds configurable and enforce at checkout/payment initiation as well
  as publication. A repeated import/poll of old evidence does not reset the clock.
- This applies to supplier-offer price validity, not the separate reference 24-hour
  rule, owned-lot expiry/eligibility or the 5,000,000-toman free-delivery threshold.
  Other valid offers/eligible owned stock require source-aware availability rules.
- No production settings changed.

### D-022 — Fixed Tehran delivery fee below the free-delivery threshold

- User selected one fixed fee for now, replacing the earlier manually quoted
  per-order fee for checkout. Area-based fees are not the launch approach.
- For qualifying goods subtotal below 5,000,000 toman, apply the fixed delivery
  fee; at or above the threshold, customer delivery payable is zero (D-019).
- Exact fee in toman is still awaiting user input. Keep fee/threshold configurable;
  do not invent an amount, geographic exception or delivery-time promise.
- Final goods and delivery amounts can now be calculated automatically at checkout
  once the fee is specified. Owner-arranged consolidated delivery in Tehran remains.
- This supersedes D-017's manual per-order quotation component; its size-based
  delivery saving is implemented by the confirmed threshold. No production change.

### D-023 — Initial fixed delivery fee: 250,000 toman

- On 2026-10-27 (Asia/Tehran), user explicitly confirmed that “250” means
  250,000 toman for delivery below the 5,000,000-toman free-delivery threshold.
- Initial Tehran fee is 250,000 toman when goods subtotal after product discounts,
  excluding delivery, is <5,000,000 toman; delivery payable is zero at or above it.
- Keep fee/threshold editable; these are initial values. No production change.

### D-024 — Paid-order shortage handling and replacement proposals

- User accepted the proposed paid-order shortage flow. Check eligible owned stock
  and other suppliers for the same item at the paid price; flag owner approval
  before buying at a loss. Do not charge the customer more without approval.
- If the same product cannot be supplied, offer replacement, remove/refund the
  affected item, or cancel/refund the whole order, at the customer's choice. Hold
  dispatch while waiting and assign an operator/next action.
- If removing an unavailable line drops the cart below the free-delivery threshold,
  retain the original agreed delivery charge rather than adding a new charge.
- User also refers to an existing replacement-proposal capability/available
  replacements and wants it incorporated. Reuse/extend what exists; its precise
  source and behavior are not established by the targeted source-keyword search.
  Avoid claiming it was located or asking for a repeat upload. Matching same item,
  suggested substitute and customer acceptance remain distinct events.
- Exact refund channel/timing, handling price differences and customer-contact
  mechanism remain open. No real order, message or refund was sent.

#### D-024 clarification — purpose of substitution suggestions

- User clarified that the desired system suggests substitutes for products that
  became unavailable. The customer remains in control of accepting a replacement.
- Whether a specific implementation already exists and is deployed remains unverified;
  inspect and reuse existing appropriate functionality as part of execution.
- Candidate-generation proposal: eligible available products with comparable type,
  size/packaging and current price, sourced from owned stock or verified supplier
  offers. Ranking and eligibility details are design proposals, not user decisions.
- A suggested item is not an automatic order change. Price differences require an
  explicit accepted adjustment; do not charge or refund silently.

### D-025 — Guest checkout with optional account and email

- User accepted guest checkout using name, mobile number and delivery address;
  account creation and email are optional. This does not add an operator quote or
  confirmation step before payment; D-020 direct checkout continues.
- Provide secure guest order status/replacement choice and recovery; exact
  verification/communication method and delivery channel remain to be designed.
  Do not assume existing SMS/email integrations or send real messages in planning.
- Optional accounts support repeat orders, including small retailers.

### D-026 — Batch-based clearance pricing inputs

- User wants the clearance price based on purchase date, production date if
  available, expiry date, purchase price, and the item's current market “date”
  (user wording). Whether “date” means current market price needs confirmation.
- This supersedes treating clearance as just a fixed discount relative to the
  current selling price; preserve market comparison and lot-specific provenance.
- Proposed inputs beyond the user list: current date to calculate age/remaining
  life, same-product/unit market observation and its verification timestamp.
  Do not use the extraction-reference anchor as market price automatically.
- Purchase age and production-to-expiry life are distinct: production can precede
  purchase. Missing production date must not be fabricated. Unknown expiry or
  market data needs an explicit review/fallback policy, not invented measurements.
- Formula/weights, margin or below-cost approval, batch allocation, market source,
  and interaction with basket discounts remain unconfirmed. No percentages or
  automated purchases/pricing were approved by this answer.

### D-027 — Clearance pricing uses current market price

- User confirmed “current market price” as the intended input in D-026.
- The batch calculation considers purchase date, production date when available,
  expiry date, batch purchase cost, and current market price of an equivalent item.
- Formula, market-price source, automatic price changes, loss approval and interaction
  with basket discounts remain open; user's confirmation does not select a formula.

### D-028 — Clearance price below the normal-period basket price

- User clarified that “lower” means a lower selling price and therefore a larger
  discount during clearance, compared with the same product's normal-period price.
- Retain the existing normal-period basket discount formula as the baseline. For
  an eligible clearance batch and the same basket conditions, calculate a lower
  final selling price using purchase date, available production date, expiry date,
  purchase cost and current market price. Do not replace normal pricing with an
  unrelated fixed-clearance model or double-count the same saving.
- The clearance calculation, timing/weights, below-cost authority, market source,
  batch allocation and exact display remain unconfirmed. If a lower feasible price
  cannot be determined, an explicit operator-review path is a recommendation, not
  authority to silently sell at a loss. No production change is authorized.

### D-029 — Bidirectional Sheets updates and manual corrections

- User may edit supplier/stock data directly in Sheets and requires bilateral data
  updating. Changes from the server must reach Sheets and supported manual Sheet
  corrections must flow back into the system. The user should not have to abandon
  the Sheets workflow.
- Preserve raw source posts separately from corrected offer/stock records; carry
  edit provenance, identity, timestamps and status through pricing/publication.
- The user did not choose Sheets or server as the sole conflict-winning authority.
  Proposed safe handling: detect concurrent edits, keep both values and create an
  operator decision rather than silently overwrite. Define supported editable fields,
  validation and actual synchronization semantics before implementation.
- Google Sheets does not itself establish atomic conditional writes/change-stream
  guarantees: design for observed revisions/reconciliation and test real races.
  Do not claim perfect real-time or exactly-once synchronization without evidence.
- No live Sheet data or production behavior was changed by this decision.

### D-030 — Surface bidirectional edit conflicts for review

- User accepted the proposed safeguard for concurrent changes between Sheets
  and the system: preserve both conflicting versions and flag the conflict for
  the user's review rather than silently choosing a winner.
- D-029 bidirectional editing remains; this decision does not assign either
  system blanket authority, guarantee atomic Sheets transactions, or change
  production. Implementation must preserve provenance, detect races, surface
  unresolved work and apply an authorized resolution through both sides.

### D-031 — Warn before clearance reaches the purchase price

- User accepted owner approval for a below-purchase-cost clearance price, then
  clarified that the key requirement is an earlier notification when the calculated
  clearance price gets close to the purchase price, so they can decide in time.
- The alert must precede reaching the purchase-cost boundary where possible; do not
  wait until a loss has already occurred. A below-purchase-cost price requires the
  user's decision before publication; “close” has no approved numeric threshold yet.
- The comparison is to the batch's actual purchase price normalized to the same unit.
  Purchase price alone does not include unallocated operating/delivery costs; do not
  label a price at purchase cost as confirmed business break-even or profit.
- Recommended design: prominent persistent WordPress dashboard alert, relevant
  batch/expiry/cost/normal and clearance-price context, acknowledgement and next
  action. Whether an additional proactive notification channel is desired is open.
- Basket discounts can change the current/future achievable clearance price.
  Recommended: assess current and lowest eligible price over supported basket
  conditions when that bound is known; flag unknown/stale inputs rather than claim
  no risk. Threshold, recurrence, cost floor and exact owner actions are configurable
  design decisions, not values invented here. No production action was taken.

### D-032 — Integrate with the current site's plugins and capabilities

- User requires complete coordination with current site plugins and use of their
  capabilities. Extend existing WordPress, WooCommerce and MolaviShop mechanisms
  rather than introducing competing customer, order, product or admin workflows.
- Active-plugin evidence from 2026-10-25 includes WooCommerce, MolaviShop, Elementor,
  Elementor Pro, Wordfence, Abrha Payment and Google for WooCommerce; activation
  does not establish a particular capability or working payment integration.
- Audit actual plugin versions/settings and supported APIs before integration,
  preserve legitimate existing configuration, and test interactions, checkout
  including classic/block, order storage/HPOS, payment callbacks, security and
  cache behavior in isolated staging. Do not disable/replace an existing plugin
  or invent a new payment provider as a shortcut. Production rollout is requested
  when the cohesive release is ready; no readiness is implied by this requirement.

### D-033 — Use site documentation; avoid unnecessary site-file inspection

- User asks to rely on the site's existing functionality documentation and avoid
  reading site files unnecessarily to conserve usage. Use existing audit, handoff
  and plugin documents; keep verification targeted to changes rather than broad
  rereads. Do not seek repeated site access solely to rediscover known functionality.
- Coordination with existing plugin capabilities under D-032 still requires honest
  staging checks. No production installation or live site files changed.

### D-034 — All normalized product fields may be edited in Sheets

- User confirmed that all product-data fields in the Products sheet should support
  manual edits and bilateral updates. Product IDs, supplier source posts, source
  timestamps, import/batch/offer identity and other provenance fields remain
  protected system evidence so a correction cannot rewrite its source history.
- Products remains the current operational product row read by the server. Offers
  and MessageData remain append-only supplier evidence. A supplier update changes
  an untouched field in Products; a Sheet-only edit remains in effect; a concurrent
  change to the same field is held with both values for the owner to choose in the
  existing WordPress/WooCommerce dashboard.
- An open field conflict removes that product from server quotes and holds its Woo
  projection. Resolution requires an owner identity and a note, checks the current
  source revision and Sheet value, and queues the existing guarded catalog publish.
- Local synthetic coverage now includes every non-provenance Products column,
  conflict resolution, stale-conflict retirement and quote/Woo holds. The supervised
  worker polls Products and Shop every 60 seconds by default (configurable 30–3600)
  and queues a durable Woo publication when their values change. This does not claim
  an atomic Google Sheets transaction, live Sheet verification or production deployment.

### D-035 — Gemini is the default supplier extraction mode

- User directs supplier product extraction to default to AI. `EXTRACTION_MODE=regex`
  remains an explicit way to select the existing deterministic parser.
- The server reads `GOOGLE_API_KEY` (while accepting recovered `AI_API_KEY` for
  compatibility) and defaults `AI_MODEL` to `gemini-3.5-flash-lite`. Gemini request
  or response failures fall back to regex as in the recovered GAS behavior. Ambiguous
  price roles, missing package counts, low confidence and invalid values stay in review.
- Google confirmed `gemini-3.5-flash-lite` and its `generateContent` method for the
  configured key on 2026-10-30. The Google API key is sent in the request header and
  must not be logged. Live supplier-message extraction quality still needs review.

### D-036 — Rezvan reference data comes from WooCommerce Store API

- Direct user correction on 2026-10-30: the Rezvan Pakhsh reference catalog must be
  fetched through WooCommerce API, not extracted from its official PDF. This replaces
  the earlier assumption in D-008 and the recovered PDF importer as a source for the
  production reference refresh.
- Required source: `https://rezvanpakhsh.com/wp-json/wc/store/v1/products`, fetched with
  stable ID ordering and complete pagination. A live read returned 3,895 products over
  39 pages, all with titles, SKUs, categories and positive prices in IRR.
- Preserve the full WooCommerce title, SKU and categories. Convert IRR to toman by 10:1;
  accept IRT as already in toman. Derive counts, retail hints, volume and weight only
  from explicit title markers; do not use Gemini for this reference import.
- Refresh creates an inactive candidate only. The owner reviews it before any
  `ReferencePrices` write or active-snapshot change. Legacy PDF/Gemini candidates are
  retained for history but cannot be reviewed or activated.
- Most WooCommerce products have no separate attributes, so the title remains the
  complete source for product details. Completeness of pages does not establish the
  correctness of supplier-provided titles or prices.
