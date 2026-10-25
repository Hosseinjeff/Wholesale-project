# MolaviSale — whole-workflow handoff for another chat

Snapshot 4.0. Updated 2026-10-25, Asia/Tehran.
Active stage: WF-00; uploaded GAS recovered and audited. Upstream refresh-server source,
installed triggers and deployment bindings remain open. No complete migration sign-off.

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
