# MolaviSale — whole-workflow handoff

Snapshot 4.50. Updated 2026-10-29, Asia/Tehran. The canonical roleplay decisions are in
`roleplay-state.json`; current live evidence and release gates are in
`pipeline-readiness.md` and `pipeline-readiness-evidence.json`.

## Current live checkpoint — 2026-10-29

Railway is healthy and its Sheet-backed catalog currently returns two products. Their
public Woo prices now use the customer price band while supplier costs stay private.
The Tehran 250,000-toman fee and free-delivery threshold are implemented. WordPress is
fresh on `h434558_Salesman`, and Shop page 7 uses the `[molavi_storefront]` WooCommerce
storefront derived from mockup v2. At the user's direction the site is now public, and
the static front page points to Shop page 7. Live `/` and `/shop/` both render two
products without the Coming Soon screen. Responsive visual/business review remains
pending.

The live Tehran shipping zone has no location rules, so customers do not match the
configured delivery method. The current environment login is WordPress administrator
user 1, while the project owner allowlist is user 3; user 1 receives the normal
WordPress access-denied response for owner-only dashboards. No access list or shipping
coverage was changed. The Rezvan → Gemini candidate remains staged and unapproved; no
`ReferencePrices` write or customer order was made. Payment configuration is explicitly
outside the current work.

The remaining non-payment work is to set the delivery coverage, inspect the owner
dashboards from user 3, review the staged reference candidate, verify Sheets and Woo
handoffs in connected staging, and complete recovery, policy, and end-to-end workflow
review. Do not mark a roleplay scene complete until the integrated stream is validated.

The user also referenced an “Auditer” ChatGPT conversation for findings. Its `read_thread`
connector was not available in this workspace, so repository audit records were used for
this pass and that conversation's findings remain to be reconciled when supplied.

## Reference acquisition clarification — D-008

The user describes Rezvan Pakhsh reference data interpreted using a Google API. The
supplied source contains exactly a PDF → Gemini → ReferencePrices implementation.
Use it as the source-backed candidate for server migration, while verifying active
settings and job bindings. The separately named REFERENCE_REFRESH_SERVER_URL path
exists in source, but its configuration/use and an external service's existence were
never verified. Earlier text treating discovery of that server as mandatory is superseded:
only investigate it as a required dependency if configuration or execution evidence
establishes its actual use. Do not block independent migration/workflow work or ask the
user to locate a presumed service merely because the wrapper exists in code.

## Confirmed execution target

The user explicitly reconfirmed that required GAS functionality belongs on the server.
GAS is the preserved reference implementation, not a planned ongoing runtime or fallback.
Use its properties to discover existing dependencies and migrate settings into server
configuration/secret storage. Do not send the user back to GAS as the target architecture.
Preserve required behavior and resolve migration findings before retiring active jobs;
server migration and live end-to-end parity are not yet complete. Continue independent
workflow/design work while unresolved external dependencies are tracked. See D-007.

## Start here

The user means the **whole operating stream**, not only the customer website:

```text
supplier data entry → processing/normalization → review → pricing
→ publication on the site → efficient customer UI/basket
→ durable order receipt → operator order management
→ supplier/customer confirmation → payment/fulfillment → tracking and aftercare
→ daily reconciliation and recovery
```

Plan and roleplay each handoff before treating a storefront redesign as a complete
solution. Determine input/output, authoritative data record, automation/manual boundary,
owner, state, screen/message, retry/exception handling, and observable acceptance per stage.

Both customer types are in scope: household buying groceries in bulk first, then a
small shop buying stock. That decision is already confirmed; do not ask it again.
The user described the intended intake: forwarded messages → bot analysis → Sheets,
plus a known-wholesaler source used as an extraction anchor. The source writes into
`ReferencePrices`. Do not repeat the primary-input question or treat the reference process
as a new feature to invent.
The user further confirms this process writes into `ReferencePrices` and asks that older
GAS iterations and remaining migration logic be audited. This was done for the available
repository source: see `gas-migration-audit.md` and `gas-migration-inventory.md`.
All fetched branches/66 reachable commits/12 GAS revisions have no sheet-name reference.
The current Python path does not consume the seeded sheet; configured GAS read-back is 404.
The user then supplied `gas.rar`: eight original source files / 176 named functions,
now preserved in `legacy-gas/recovered-2026-10-25/`. Read `recovered-gas-audit.md` and
`recovered-gas-inventory.md`. Do not request those files again or restart Google sign-in.
The source includes ReferencePrices, AI batches, matching, operator confirmations,
Inventory log and a storefront builder. Eighteen offline observation groups cover useful
contracts and defects. None of the uploaded exact file blobs matched fetched Git history.
The main refresh delegates to a separate server via `REFERENCE_REFRESH_SERVER_URL`;
a legacy path imports Rezvan Pakhsh's PDF. The upstream server source/configuration,
`appsscript.json`, installed trigger list and deployment bindings were not supplied.
The source defines daily reference refresh around 04:00 in script timezone and a
five-minute AI queue trigger; installation is not verified. See the access checkpoint.
Keep the extraction anchor distinct from consumer/reference selling-price data.

## Project and explicit constraints

Site: `https://www.molavisale.ir`.
Repository: `https://github.com/Hosseinjeff/Wholesale-project`.

Supplier-post ingestion and Google Sheets feed the catalog. WordPress/WooCommerce and
an older PHP storefront coexist and have different order/account behavior. The existing
code is evidence, not an automatically approved future workflow or proof of live reliability.

The user requires clean, professional, minimal UI with a more vibrant palette,
desktop and phone mockups, separate maximum/current discounts, intuitive next actions,
and proper documentation. **Mockup first; do not redesign production without approval.**
Iranian payment-provider exploration is deferred. The first ordering solution should
be fast and hassle-free. Guest name/mobile request and confirmation-before-payment
are proposals to resolve in WF-06/07/08, not final approved policy.

## Documents and how to resume

1. `workflow-roleplay.md`: active full-stream sequence WF-00…WF-11.
2. `roleplay-state.json`: active scene, pending question, persona, and progress.
3. `decisions.md`: confirmed requirements/answers versus proposals/open business decisions.
4. `business-flow.md`: code-backed ingestion, pricing, and sample calculations.
5. `design-decisions.md`: visual direction, customer UI, and verification boundaries.
6. `roleplay-plan.md`: older customer-focused appendix; its RP scene order is superseded.
7. `gas-migration-audit.md`: historical/current Python migration findings A-01…A-12.
8. `gas-migration-inventory.md`: historical/root inventory of 67 named GAS functions.
9. `recovered-gas-audit.md`: recovered reference contract, preservation map, B-01…B-08.
10. `recovered-gas-inventory.md`: structural inventory of all 176 uploaded functions.

No scene has been completed. Ask one concrete question at a time. Play one clearly
named role, simulate a normal case plus an important failure, and record the outcome
before advancing. The user is the business decision maker, not necessarily the operator
or customer role in every scene. Use sample data only.

## Current implementation and honest verification status

- Migration audit: 64 existing offline tests pass, but eight differential fixtures and
  targeted reproductions show unconsumed reference data, weak cross-batch idempotency,
  ambiguous rollback acknowledgements, retained legacy writer/controls, incomplete media
  receipts, non-durable order acknowledgements, and stock-status loss at publication.
  These are audited findings, not production fixes. The uploaded anchor logic is recovered;
  its separate upstream refresh service and live bindings still need verification.
- The recovered GAS adds useful reference/AI/inventory behavior absent from Python.
  It also contains stale-reference acceptance, permissive conflicting matches,
  message-wide price-role corrections and a selected-supplier/cost mismatch. Do not
  port these defects. Inventory status/eligibility and token/reply contracts also differ.
- Resolve authority explicitly: GAS uses Inventory log and fixed markups; Python/Woo
  uses Offers/Products/Items and basket price bands. Keep the user's maximum/current
  discount requirement; neither historical default is newly approved business policy.

- v1 desktop image: `https://www.molavisale.ir/molavi-design-preview-e73014a831.html`.
  The user could open this browser link; inline images/local file links failed to display.
- Versioned v2 draft: `design/mockup-v2/` contains an interactive responsive storefront with
  separate maximum/current discounts, price changes, basket editing, phone bottom sheet,
  search/filter/sort, and demonstration guest request. Sample prices come from existing code.
- Local bundle: `/workspace/scratch/mockup-v2/`. JavaScript syntax checked; browser tests
  did not finish because Chromium launch was permission-blocked and the retry interrupted.
  Do not claim completed UI verification, screenshots, a published v2, or design approval.
- GAS source, audit/continuity docs and the v2 mockup source are preserved in GitHub.
- The user has now authorized repository preservation of the GAS source and audit/
  continuity docs. Start at `legacy-gas/README.md` in GitHub; use the inventory and
  targeted source ranges to conserve usage. The user subsequently authorized pushing
  the six mockup source files too; they remain an unapproved, unverified design draft.
- No real order/payment was submitted and no storefront theme change was published.
- Historical WordPress/cPanel access was verified. Recheck current readiness through
  configured tools; never include credentials, private customer data, or live supplier
  cost values in public documentation.

## Pricing and data rules not to lose

- Every eligible product has its own maximum discount; current discount varies with basket.
- The current selling price already includes that current discount. Never subtract it twice.
- Adding/removing eligible products reprices other products. Define quote-lock policy explicitly.
- Progress uses eligible **reference value**, not discounted payable subtotal. The default
  2,000,000 toman target in code is configurable and not newly verified live business policy.
- The target is not a minimum order or free-delivery threshold. Basket discount is a
  weighted savings percentage, not the mean of product percentages.
- Minimum purchase is not automatically a multiple-of-pack rule.
- Supplier offers/imported rows/current `instock` flags do not establish verified stock.
- Decide one authoritative product/order ownership model; do not create competing site/sheet masters.
- Do not add invented shipping guarantees, stock promises, testimonials, or payment badges.

## Sharing and checkpoints

Shared publication targets:

- Reading page: `https://www.molavisale.ir/molavi-workflow-e73014a831.html`
- Complete Markdown: `https://www.molavisale.ir/molavi-workflow-e73014a831.md`
- Machine state: `https://www.molavisale.ir/molavi-workflow-e73014a831.json`

Verify publication before telling the user a new snapshot is available. The publication
receipt in `shared-brief-publication.json` records the last confirmed version; local
edits or a successful build alone do not establish that the public brief was updated.

Another chat needs the URL or exported file; it does not inherit conversation history,
file permissions, or publishing access. After each scene update the state, decision log,
handoff, and affected requirements; explicitly refresh the public snapshot if allowed.
If publishing fails, say which snapshot is actually accessible and provide the latest
checkpoint. Independent chats need an explicit merge, not assumed automatic synchronization.

### Starter prompt

```text
Continue MolaviSale using this brief: [paste complete Markdown URL].
Read the handoff, whole-workflow roleplay, decision log, business flow, design notes,
and state. Cover the entire stream from supplier data entry through order follow-up.
Both customer types are confirmed: household first, small shop second. Resume at the
recorded stage, ask one concrete question at a time, and write a checkpoint after each.
Mockups first; do not change production or choose a payment provider yet.
```

## Local continuation after D-008

D-009/010/011 confirm per-occurrence owner choice on stale/failed refresh, a 24-hour
cutoff and the private WordPress dashboard channel. Local server/PHP drafts now live
in the repository working tree; see reference-server-draft.md and
reference-dashboard-integration.md. Twenty-seven focused offline tests pass. Read-only
wp-admin authentication and active-plugin inventory succeeded; production is unchanged.
PHP 8.3 syntax checks pass; WordPress runtime/browser compatibility and live
integration are not verified. No scene is complete,
no production authorization or mockup approval is implied, and public snapshot 4.0
has not been updated. Earlier notes saying WordPress inspection or Git access is
blocked are superseded; credentials worked once execution network access was enabled.

## Latest local migration checkpoint

The server now assesses all saved live incidents for its active snapshot, even when
a task supplies no failure ID. ReferenceStore stages immutable versions, requires
owner review, activates with expected-version protection and atomically retires
old incidents while retaining history. Live refresh/ingestion is not wired yet.
35 focused Python tests and 14 real WordPress/WooCommerce runtime assertions pass.
The disposable runtime used WordPress 7.1.3 and WooCommerce 11.2.1 with synthetic
users and mocked HTTP; no production credentials/data or site mutations. Browser
layout and Elementor/Wordfence interactions remain unverified. Earlier PHP-runtime
pending notes are superseded by docs/reference-dashboard-runtime-evidence.txt.

Pending user question: with synthetic supplier price 420,000 versus reference
480,000 toman, retain supplier amount and review identity/unit uncertainty only,
or review every price difference? This is not yet a confirmed policy. No full WF
scene is complete, and production/mockup/public-brief approval remains unchanged.

## Whole-project priority correction

The user asks for a whole-pipeline functional check before resuming roleplay and
explicitly deprioritizes component details. docs/pipeline-readiness.md records the
current result: existing server/bot/catalog/storefront respond, but required reference
migration, durable handoffs and order fulfillment/follow-up/recovery are incomplete.
Do not present local dashboard tests as full-project readiness. The last detailed
price-comparison question is deferred, not answered. No production changes or live
order/import replay were performed. Next work must focus on whole-stream gaps.

## Whole-flow handoff checkpoint — 2026-10-26

Local durable supplier inbox/batches, separate publish/notification jobs and order
receipt/retry behavior are implemented and tested. WooCommerce classic/block
checkout share a matching-written-receipt check and scheduled retry. 112 Python
tests and 19 isolated WordPress/WooCommerce assertions pass; see
docs/pipeline-handoffs.md and its evidence. No production setting/plugin changed.
The worker/storage mode is opt-in and requires supervision/persistent storage.
Reference matching/extraction is still not wired into intake; stock propagation,
fulfillment/follow-up and unified operator recovery remain incomplete. No whole
scene or live end-to-end sign-off is claimed. Continue on those whole-project
connections; do not reopen the deferred price-comparison micro-question.

## Latest direction — plan first, one coordinated execution

The user now requests completion of the entire plan before executing it as one
coordinated effort. Stop piecemeal implementation. Start with
`docs/master-execution-plan.md` (v1.0 draft, 2026-10-26) and the grouped pending
business inputs in roleplay-state.json. The plan covers full workflow/data ownership,
all required GAS dispositions, WordPress/Woo integration, customer/operator UI,
fulfillment/aftercare, roleplay acceptance and release/rollback. Proposed business
choices are not confirmed by silence. Existing production and mockup boundaries
remain; no new live change is authorized. Existing 112/19 test evidence is component
evidence, not completion of the master plan.

### Planning update — D-012

User selected consolidated delivery for the first release. Master plan is v1.1:
include collection/receipt checking/packing before customer dispatch; exclude direct
supplier/hybrid delivery from launch scope. Stock-in-advance versus per-confirmed-
order procurement remains the next planning question. This answer does not resolve
record ownership, merchant responsibility, checkout/payment or delivery area.

### Planning update — D-013

User intends demand-led buying with extra units sometimes purchased for lower cost;
owned stock management and expiry-aware clearance relative to current selling price
are now explicit scope. Master plan v1.2 includes purchase batches/reservations,
surplus and clearance design. FEFO, overbuy approval, budget controls and best-price
discount interaction are recommendations, not confirmed policies. Next question is
owner approval versus automated authority for extra purchases. Keep planning the
whole project before the coordinated implementation; no production actions authorized.

### Planning correction — D-014

Most suppliers impose fixed selling units recorded in their posts/Sheets. Necessary
carton rounding, not merely optional cheaper bulk buying, creates surplus stock.
Master plan v1.3 maps source purchase-pack terms separately from customer minimums,
computes required packs against unmet demand and tracks received surplus. The
optional-overbuy approval question is withdrawn. Do not ask it again as the default
stock-model question or assume supplier quantities can be negotiated.

### Next planning gate — customer payment/procurement timing

Master plan v1.4 proposes request → availability/pack/cash-exposure check → accepted
final quote → verified manual payment → supplier purchase → consolidation/delivery.
Full prepayment versus deposit versus pay-later is pending user input, not confirmed.
Funding of pack surplus must be shown separately from the customer's quantities.
Do not choose a gateway, charge cancellation fees or execute purchases during planning.

### Planning update — D-015

User selected full confirmed customer payment before supplier procurement. Master
plan v1.5 now includes this confirmed gate; deposits/pay-on-delivery are excluded
from that first-release path. Payment method and verification owner remain open;
gateway selection is still deferred. Next planning input: first delivery area and
who performs customer delivery. No other pending proposal is automatically approved.

### Planning update — D-016

User confirmed Tehran initially and owner-arranged delivery. Master plan v1.6
records this without inventing fleet, fees, time guarantees or surrounding-city
coverage. Next question is per-order delivery quote before payment versus an
owner-defined fixed/zone tariff. Consolidation and full verified payment before
procurement remain confirmed. Exact boundary and packing assignees remain open.

### Planning update — D-017

User accepts per-order delivery quotation and requires an order-size delivery
discount. Master plan v1.7 separates delivery charge/saving/payable from product
discounts. Next input is the size metric; recommendation is goods subtotal after
product discounts, before delivery. No amounts, free-delivery threshold or discount
bands are confirmed. Do not repurpose the existing product-discount target.

### Planning update — D-018

User requests free delivery at a fixed cart-size threshold. Master plan v1.8 records
this; threshold value and calculation basis remain open. Recommendation is goods
subtotal after product discounts, excluding delivery. Keep this separate from the
existing product-discount target; no partial discount below threshold is confirmed.
Next question is the fixed amount in toman. No live price/settings changes occurred.

### Planning update — D-019

User wants a rounded mid-range 24-can tuna-pack equivalent for free delivery. A
read-only live public catalog check shows a middle example at 210,000 toman;
24 × 210,000 = 5,040,000. Master plan v1.9 sets the derived initial threshold to
5,000,000 toman after product discounts, excluding delivery. This is the assistant's
rounded planning value from the user's benchmark, not a user-typed exact figure.
Keep it fixed/configurable, separate from product-discount targets, and do not
automatically tie future changes to a SKU. No production settings changed.

### Planning continuation — customer submission journey

User accepted the presented 5,000,000-toman free-delivery threshold. Master plan
v2.0 next proposes guest request with name/mobile/Tehran address, operator final
availability/delivery quote, then accepted full payment and procurement. Guest/
field policy is awaiting confirmation; do not infer it from previous delivery answers.
Continue plan completion at workflow level, with implementation still deferred.

### Planning correction — D-020 direct checkout

User rejects the manual post-cart order/quote confirmation step. Master plan v2.1
now uses direct checkout/payment with supplier-price warning and unavailability
thresholds. Proposed time-since-verification metric and exact values await input.
Keep these separate from reference TTL24h and free-delivery5m. Below-threshold
delivery must become calculable at checkout; the old manual per-order quote is a
real conflict to resolve. Guest/account policy remains unanswered. Verify the
installed payment integration in staging; no new provider or live charge approved.

### Planning update — D-021 supplier price age

User confirmed warning at one week and unavailability at two weeks since last
verified supplier price. Master plan v2.2 uses >=7-day warning and >=14-day block
as editable initial settings. Reference TTL24h and owned-stock expiry are separate.
Next checkout issue: fixed versus area-based Tehran fee below free-delivery5m,
replacing the earlier incompatible manual per-order quote step. No live changes.

### Planning update — D-022 fixed Tehran delivery fee

User chose a fixed fee for launch below the 5,000,000-toman free-delivery threshold.
This supersedes the earlier manual per-order quote and makes direct checkout
calculable once the numeric fee is provided. Master plan v2.3; next question is
the fixed fee in toman. Do not invent amount, area exceptions or timing promises.

### Planning update — D-023, 2026-10-27

User explicitly confirmed 250,000 toman as initial fixed Tehran delivery fee below
the 5,000,000-toman free-delivery threshold. Master plan v2.4; no amount ambiguity
remains. Threshold basis is discounted goods subtotal excluding delivery. Keep both
values editable. No production settings changed; continue remaining whole-plan
identity/ownership/paid-exception decisions rather than reopening delivery amounts.

### Planning continuation — paid-order supplier shortage

User chose to address problems after payment. Master plan v2.5 has a synthetic
shortage scenario and a proposed same-product alternative, customer replacement/
refund choices and original-delivery-charge protection. These are not confirmed;
the grouped policy question is pending. No full scene or production action is done.

### Planning update — D-024 paid-order shortage

User accepted same-item fallback at original paid price, owner approval before a
loss, customer choice of replacement/partial refund/full cancellation, and honoring
the original delivery charge after shortage removal. User references an existing
replacement-proposal capability / replacements already available. Targeted keyword
search of current app and recovered GAS did not identify its source; do not claim
it is absent, make a separate replacement system automatically, or ask for repeat
source upload. Verify existing Woo/plugin and system behavior when implementing.
Refund settlement/channel/timing and exact replacement mechanism remain open.

### D-024 clarification — substitution suggestions

User clarifies the desired system suggests substitute products for an unavailable
ordered item. Master plan v2.7 records customer choice, proposed candidate matching
and explicit price-adjustment handling. Whether a specific existing implementation
is already deployed remains unverified; reuse it if appropriate. Continue the whole
plan; do not assume the user approved the proposed candidate-ranking rules.

### Planning continuation — checkout identity

Master plan v2.8 proposes guest checkout with name/mobile/Tehran address and
optional account/email; user has not confirmed this. It respects D-020 direct
checkout and D-015 full verified payment before procurement. The next question
is guest versus required-account checkout. Status recovery/verification remains
a design task; do not assume an SMS integration.

### Planning update — D-025 guest checkout

User confirmed guest checkout with name/mobile/address and optional account/email.
Master plan v2.9 now asks how expiry-clearance and basket discounts interact; best
eligible price without stacking is only a recommendation. Exact secure guest-status
recovery and communication method are still design tasks; no SMS provider assumed.

### Planning update — D-026 batch-based clearance pricing

User wants clearance price derived from purchase date, production date if available,
expiry date, purchase cost and the current market “date” (exact wording). Clarify
whether they mean current market price. Master plan v3.0 separates purchase age
from production-to-expiry shelf-life, retains lot cost/provenance and flags missing
data. Earlier lower-of-clearance-and-basket-price idea was not approved. Formula,
market source, loss controls and basket interaction remain to be designed.

### Planning update — D-027 current market price

User clarified D-026 means current market price. Master plan v3.1 suggests an
illustrative market-to-purchase-cost clearance curve; this is not approved or
configured. Below-cost proposals with owner approval versus never proposing below
cost is the next question. Market source, rules and basket interaction remain open.

### Planning correction — D-028 clearance versus normal price

User clarified clearance means a lower selling price/more discount than normal,
using the existing normal-period basket formula as the basis and the lot/market
inputs already specified. Master plan v3.2 records this. Do not interpret it as
a smaller discount or choose between two unrelated prices; calculate one final
clearance price without double counting. Numeric formula/weights, loss authority,
market source, batch allocation and missing-data policy remain open.

### Reconnected workspace — clearance proposal saved

Workspace recovered after an executor disconnect; local changes and decisions through
D-028 were verified intact. Master plan v3.3 links a reviewable calculation in
docs/clearance-pricing-design.md. The interpolation, cost floor, urgency parameters,
market source and below-cost handling are proposals, not user-confirmed policy.
Existing work remains local/uncommitted; no production changes were made.

### Plan continuation — remaining operations, 2026-10-28

Master plan v3.4 links operations-and-ownership-plan.md, which covers roles, order
lifecycle, paid shortages, fulfillment/aftercare, record ownership, reconciliation
and connected-staging replays. User-requested whole-plan execution remains the target.
Server/Woo/Sheets authority is a recommendation and is not yet selected; direct Sheets
editing behavior is material. Clearance loss approval and aftercare terms also remain
open. Existing production and mockup constraints remain unchanged.

### Planning update — D-029 bilateral Sheets editing

User may directly edit supplier/stock data in Sheets and requires two-way updates.
Master plan v3.5 and operations-and-ownership-plan.md preserve this operational
workflow. Proposed conflict handling keeps both values for authorized review;
exact record authority/conflict policy is not yet selected. Do not silently convert
Sheets to one-way reports or assume Google Sheets offers atomic cross-system
transactions. Test direct Sheet edits, server updates and races in staging.

### Planning update — D-030 synchronization conflicts

User accepted preserving both versions and flagging concurrent Sheet/system edits
for review. Master plan v3.6 records this; Sheets bidirectionality remains required.
Next material decision is whether below-purchase-cost clearance prices require owner
approval. No production behavior was changed.

### Planning update — D-031 early near-cost notification

User accepted owner approval for below-purchase-cost clearance but emphasizes
notification before the calculated price gets close to the buying price, so they
can decide before a loss. Master plan v3.7 and clearance-pricing-design.md record
the confirmed early alert/approval behavior. Proximity threshold, recurrence and
additional notification channel remain open; dashboard is proposed. Account for
basket-dependent possible prices and do not equate purchase cost with full profit.

### User requested whole-plan execution and deployment — 2026-10-28

User asked to note remaining details for later iterations and deploy the plan as a
whole. Production deployment intent is explicit; do not ask for it again merely
because older handoffs say it was not authorized. The coherent release is not yet
implemented/validated and the mockup remains unapproved. Track actual missing
integration versus later policy choices in docs/release-iteration-backlog.md.
New local progress: explicit supplier out-of-stock survives Woo projection; pure
Sheets three-way conflict and confirmed delivery/price-age policy helpers tested.
120 Python tests and 21 isolated WP/Woo assertions pass. None establishes complete
end-to-end readiness. Existing separate FollowUP-bot Railway approval is unrelated
and was untouched. No production deployment or configuration was performed.

### User constraint — D-032 existing site plugin coordination

User requires complete coordination with current site plugins and reuse of their
capabilities. Master plan v3.8 and the release backlog now make active-plugin
inventory, supported APIs, checkout/payment/security/cache compatibility and staging
verification cross-cutting release requirements. Do not assume an active payment
plugin works or introduce competing systems. Whole-plan deployment is requested
but not yet ready or performed.

### 2026-10-28 execution continuation and site-reading preference

User directs us to use site functionality documentation and avoid unnecessary
site-file reads (D-033). Continue the whole requested release rather than stopping
after notes. Current local draft adds a native Woo shipping method, fixed-pack
stock lot/reservation prototype and supplier freshness evidence ledger. 130
Python tests and 25 isolated Woo assertions pass. These are not integrated or
deployed: shipping-zone/territory/tax/full checkout, Sheets two-way synchronization,
reference matching, inventory/Woo/order lifecycle, notifications, recovery and
whole-stream staging still require work. See release-iteration-backlog.md. No
production change or acceptance of any other Railway deployment was performed.

### Execution continuation — reference/stock/checkout drafts

User asked why work stopped and instructed reliance on site documentation rather
than unnecessary site-file reads (D-033). Local work continued: fixed-pack lot
receipts/reservations, revision-bound supplier freshness evidence, conservative
reference matching hints and a Woo-native delivery method were drafted and tested.
134 Python tests and 25 isolated WordPress assertions pass. These pieces are not
yet connected into a complete working pipeline or production; release backlog
records remaining integration and staging. No site inspection/deployment was made.

### 2026-10-28 continued execution — procurement and zone flow

User asked why work stopped and directed use of site documentation, not broad site
file inspection. Work resumed: local fixed-pack/minimum/multiple procurement
planning and native Woo shipping-zone calculation were added. Synthetic tests cover
138 Python cases and 27 Woo assertions including threshold/coupon/IRT-to-IRR/zone.
These remain draft components, not connected full checkout/order/intake/Sheets or
a deployed release. See release-iteration-backlog.md and evidence. No real purchase,
order, site file inspection or production change occurred in this continuation.

### Execution continuation — first reference-to-intake connection

User asked to implement rather than stop. Opt-in durable intake now consults the
reference gate, protected decision API and stores snapshot/match/hint/warning in
Offers without altering supplier prices; unmatched references enter needs_review.
A synthetic stale-reference → owner choice → intake replay passes. 142 Python tests
and 27 isolated Woo assertions pass at this earlier checkpoint. This is not full
GAS matching/AI/item-review parity, a live Sheets synchronization or end-to-end
launch. The snapshot-change publication race described here is addressed for
server-controlled writers in the later crash-recovery checkpoint below; controlled
cutover from legacy/GAS writers remains required.

### 2026-10-28 execution continuation — crash recovery and quote availability

The reference write/publication guard now uses a persistent SQLite hold, a shared
cross-process publication lock and a reference activation lock. If a worker dies in
an external Sheets write, the expired batch is moved to owner review instead of being
silently retried. The private Woo dashboard requires a reconciliation note and an
explicit replay action; after that replay verifies successfully, a separate guarded
publish job can run. App writers coordinate through these locks, but legacy/GAS
writers still bypass them and require controlled cutover. Supplier price freshness
is now tied to offer/cost revision and original source timestamp: the owner dashboard
shows day-7 warnings, server quotes and Woo purchasability block at day 14, and manual
verification is audited and queues a guarded catalog refresh. Explicit supplier
unavailability is excluded from server cart quotes. 155 offline Python tests and 41
isolated WordPress 7.1.3/WooCommerce 11.2.1 assertions pass. Continuous Sheet-edit
synchronization, item-level reference review, owned-stock/procurement-to-order wiring,
full payment and fulfillment staging remain open. No live site, Sheet or production
service was changed.

### 2026-10-28 execution continuation — all-field Sheets reconciliation

User confirmed every normalized product-data column in Products may be edited in
Sheets. IDs, original supplier posts, source timestamps and import/offer provenance
remain protected. Products is the operational row read by server catalog/quote paths;
Offers and MessageData remain append-only evidence. On a supplier update, three-way
field reconciliation preserves Sheet-only edits, writes server-only changes, and
stores concurrent/invalid edits for review. The existing WooCommerce dashboard now
shows both values and requires an owner choice plus note. An unresolved conflict
removes its product from server quotes and holds its Woo projection. New source/value
observations retire obsolete conflict cards. Google Sheets disjoint cells are written
in one batch after fresh rereads, with race detection and post-write verification;
the API does not provide a cross-system compare-and-swap guarantee.

168 offline Python tests and 46 disposable WordPress 7.1.3/WooCommerce 11.2.1 runtime
assertions pass. Tests cover every non-provenance Products column, invalid numeric
repair, a Sheet edit arriving between read and write, conflict resolution and quote/Woo
holds. Sheet-only changes now schedule durable Woo publication through the supervised
worker's default 60-second poll (configurable 30–3600 seconds). Tests exercise polling,
coalescing, stale-job replacement and worker receipts with synthetic data. This remains
synthetic: no live Sheet was changed. Connected staging must verify direct edits,
freshness invalidation, publication cadence and row movement; durable storage, worker
cutover, reference item review, paid-order inventory/procurement, payment, fulfillment
and aftercare remain on the release path. No production change occurred.
The configured `GOOGLE_SERVICE_ACCOUNT_JSON` variable is present in this executor but
fails JSON parsing at line 2, column 3, so the read-only Sheet check stopped before any
Google API request. No credential value was printed or changed.

### 2026-10-28 continued execution — Gemini supplier extraction

D-035 sets Gemini as the default supplier extraction mode; `EXTRACTION_MODE=regex`
selects the existing deterministic parser. `catalog/gemini.py` uses `GOOGLE_API_KEY`
(and accepts recovered `AI_API_KEY`), defaults to `gemini-3.1-flash-lite`, rejects
incomplete/ambiguous output, routes uncertain price roles/counts and low confidence to
review, and falls back to regex after request/response errors. Message retry reuses the
same extraction result to avoid repeat provider calls. The Rezvan PDF importer reads
the same server key/model defaults but still has no scheduled caller. 177 offline Python
tests and the prior 46 isolated WordPress assertions pass; Gemini was mocked and no live
API call was made.

Read-only Railway inspection found the `GOOGLE_API_KEY` addition staged on the
`Wholesale-project` production service, not active. `GOOGLE_SERVICE_ACCOUNT_JSON` exists
there but the connected Railway view redacts its value. The executor's available copy
still fails JSON parsing at line 2, column 3, so no live Sheet request was possible.
No Railway deployment or variable change was made by the agent.

### 2026-10-28 continued execution — verified Woo payment mirror

The Woo order handoff now emits a second snapshot from `woocommerce_payment_complete`
with Woo status, payment state, payment method, transaction reference, paid timestamp
and stable catalog SKU/product IDs. The server stores these in separate order fields;
line SKU falls back to the stable product ID. The paid-state receipt is idempotent,
retries through Woo Action Scheduler/WP-Cron, and an old initial-order retry cannot
overwrite a newer paid snapshot after success. A retry whose Woo order is no longer
paid is discarded instead of replaying stale paid state.

178 offline Python tests pass. 54 isolated WordPress 7.1.3/WooCommerce 11.2.1 runtime
assertions pass, including successful and failed/retried `payment_complete` updates.
The payment transport is mocked: the installed gateway's verified amount/callback
and live refund handling remain unverified. No live checkout, payment, purchase or
deployment occurred.

### 2026-10-28 execution continuation — lot receipts and paid-order procurement plan

The paid Woo handoff now carries each line's exact supplier offer ID through the
order sheet and stores a durable plan after the server confirms the order mirror.
Owner-only Woo dashboard forms record received lots with SKU, supplier, units, unit
cost, production/expiry dates and an audit note. Eligible lots are reserved FEFO and
idempotently; cancellation/refund/failure updates release still-reserved units.
Any remaining demand becomes an owner-noted fixed-pack proposal with pack size,
minimum/multiple, cost and surplus. The proposal is tied to the paid line's source
offer and never submits a supplier purchase. The server refuses altered order content
and the dashboard receipt checks bind to the matching order/lot result.

The full offline Python suite passes 184 tests. The disposable WordPress 7.1.3 /
WooCommerce 11.2.1 runtime passes 65 assertions, including owner authorization,
stock-receipt replay/conflict, paid reservation, terminal order status, checkout-time
SKU/offer freezing and proposal-only behavior. Inventory and fulfillment records remain in
SQLite, are not mirrored to Sheets, and have no provisioned production volume/worker.
The installed payment gateway, whole checkout, customer replacement/refund choice,
packing, dispatch, tracking, follow-up, live Sheets and Elementor/Wordfence behavior
remain staging work. No real lot/order/payment/purchase or deployment occurred.

### 2026-10-28 execution continuation — paid order follow-up

`catalog/order_operations.py` and the private Woo dashboard now carry paid orders
through owner assignment, actual supplier purchase/receipt recording, shortfall
replacement proposals, the customer's replacement/partial-refund/full-cancellation
choice, refund and additional-payment receipts, packing, owner-arranged dispatch and
tracking, delivery, aftercare and closure. Substitutes are rechecked against current
owned stock and price bounds. Outstanding refund totals cannot exceed the original
paid order amount. A supplier delivery already in flight after customer cancellation
is recorded as unreserved stock. All actions require an owner note and are idempotent;
no supplier order, customer message, refund or extra charge is triggered automatically.

The final local suite passes 196 Python tests and 70 disposable WordPress 7.1.3 /
WooCommerce 11.2.1 runtime assertions. The workflow is not enabled on Railway. A
read-only Railway check confirms `GOOGLE_API_KEY` is staged but not active;
`GOOGLE_SERVICE_ACCOUNT_JSON` and `GOOGLE_SHEET_ID` exist but values are redacted;
the Wholesale service is sleeping; and `PIPELINE_DB_PATH`, `REFERENCE_BRIDGE_TOKEN`
and `REFERENCE_OWNER_IDS` are absent. The connected production service was not changed.
Persistent storage, owner binding, connected Sheets/gateway checkout and customer
communication/refund behavior remain release prerequisites. No roleplay scene is
complete yet.

### 2026-10-28 continuation — owner review of reference matching

The current work adds weighted candidate suggestions, persistent snapshot/offer-bound
owner decisions, unit-versus-package confirmation and correction from recent decisions
to the private Woo dashboard. Offers remain append-only. A confirmed carton amount is
normalized to a per-unit cost only in the current Products projection, with the pack
minimum retained; an unresolved/no-match item stays held. A successful correction
queues the existing guarded Woo publication path. The server rechecks case, owner,
active snapshot and chosen reference before it accepts the decision.

202 Python tests pass, and the isolated WordPress 7.1.3/WooCommerce 11.2.1 runtime
passes 74 assertions. A read-only Sheets probe in the current executor failed before
an API request because its service-account JSON did not parse. This does not establish
whether Railway's separately stored credential is valid. No Sheet data was read or
changed, and no Railway configuration or deployment was changed. Remaining reference
work is live refresh/staging and full GAS history/category/AI confirmation parity;
the wider staging needs from the preceding checkpoint remain.

### 2026-10-28 local parity and reference-refresh continuation

The local server implementation now covers the recovered category/history price-scale
fallback for Gemini-extracted offers and the owner AI-confirmation lifecycle. The private
MolaviShop dashboard can confirm extraction fields or mark a message not-a-product;
decisions are durable/idempotent, raw supplier rows remain unchanged, and publication
continues through the guarded queue. The Rezvan PDF → Gemini refresh now has a crash-aware
24-hour local worker, failure records, immutable staged candidates, owner review/activation
and a verified `ReferencePrices` projection path.

Verification: the complete offline Python suite passes 235 tests. The disposable real
WordPress 7.1.3/WooCommerce 11.2.1 runtime passes 93 assertions, including AI, reference,
operations, clearance proposal and historical-date correction screens. Gemini and Sheets
are mocked in these runs; no live PDF/API
request, sheet read/write, order, payment, production deployment or Railway mutation was
performed. `docs/reference-dashboard-runtime-evidence.txt` contains the refreshed runtime
receipt.

The previous read-only Railway snapshot is historical. The user has since reported updating
`GOOGLE_API_KEY` and `GOOGLE_SERVICE_ACCOUNT_JSON`; current read-back/activation and live
validity are not verified. The local executor's unrelated Sheets probe failed before making
an API request, so it says nothing about Railway's separate value. Private persistent
storage, supervised worker, owner bridge, installed gateway behavior, connected staging,
legacy writer cutover and browser/plugin compatibility remain release gates. The unified
held/failed work view is now implemented locally; a private clearance proposal workflow
is also implemented, while its approved formula, near-cost threshold and proactive
recalculation remain tracked follow-up work. No roleplay scene is complete, and the
mockup remains unapproved.

Read-only Railway MCP metadata checked 2026-10-28: `stellar-solace` has only a production
environment. `Wholesale-project` has one staged addition (`GOOGLE_API_KEY`), a sleeping
latest deployment, and no attached volume. The staged key is not active. The service
account and Sheet ID variable names are present, but their values were not read; the user
reported updating the service account, and its validity remains unverified. The durable
database, reference store, bridge token and owner IDs are absent. This project has no
connected staging target, so activating the key would change production without enabling
the supervised durable workflow. No Railway configuration or deployment was changed.

### 2026-10-28 audited inventory continuation

The private MolaviShop Woo dashboard now supports owner-audited stock count corrections,
damage/expiry deductions and lot holds/releases. Server events are append-only and bound
to the exact lot revision displayed in the dashboard; the returned receipt must include
the matching previous revision and durable event ID. Open reservations prevent holding a
lot, adjustments cannot reduce reserved/fulfilled quantities, and held lots are excluded
from new allocations. Existing local databases gain the reviewed-revision event field
through an additive migration.

Verification passes 241 offline Python tests and 97 disposable WordPress 7.1.3/WooCommerce
11.2.1 runtime assertions. These are local/synthetic checks. Persistent storage, Sheets
mirroring, customer-return disposition, reservation expiry/order revisions and connected
checkout/staging remain incomplete. Production and Railway were not changed.

### 2026-10-29 storefront screenshot implementation

The user supplied the current visual reference for the live store. Commit `cc5524a`
(`Match storefront to supplied visual reference`) updates the MolaviShop plugin to replace
the WooCommerce archive with a dedicated storefront template on both the Shop route and
the Shop page when selected as the static homepage. The layout has a compact RTL header,
search, category/sort controls, list/grid toggle, four-column wide desktop grid, favorite
controls, product thumbnails in the basket, and the existing responsive phone basket.
Favorites stay in the current browser and are not synchronized to accounts. The live
catalog still has two actual products; no illustrative products were added.

The update was written through cPanel UAPI to the existing plugin directory. The private
runtime configuration was not touched. Read-back matched the local commit for the main
plugin file, template, JavaScript and CSS. The host retains one pre-existing
`<meta charset="utf-8">` token in `includes/storefront-renderer.php` immediately after the
custom header opening tag; it is not visible and does not affect the layout. Both `/` and
`/shop/` return HTTP 200 and their HTML includes the custom header, product grid and basket.
A live browser inspection confirmed the header, toolbar, product grid and right-side basket.
The live catalog has two products. Search and basket interactions were not clicked during
that visual check; no order or payment action was taken. No payment configuration changed.
`node --check` and `git diff --check` pass locally. PHP CLI is unavailable in this executor;
the live WordPress page renders without a PHP fatal error.
