# MolaviSale: whole-business workflow roleplay

Version 4.0. Updated 2026-10-25, Asia/Tehran.

Scope: the complete operating stream, from supplier data entry through order aftercare.
The website is one part, not the scope of the exercise. Cover both customer types:
households buying in bulk first, then small shops buying stock.

## Outcome

An efficient, reliable process with minimal duplicate entry: who does what, where data
lives, which work is automated, what each person sees, and how errors recover. The
result informs both the customer storefront and the operator's daily work surface.

The assistant plays supplier, data-entry operator, processing system, catalog manager,
customer, order operator, or fulfillment/support operator, one role at a time. The user
is the business decision maker and can choose any role. Simulate one normal case and
one meaningful failure per stage, ask one concrete question at a time, and record the
result before proceeding. Use sample data; no real orders, publication, messages, or payments.

## Contract for every stage

| Field | What must be decided |
|---|---|
| Input | Source, format, identity, required fields, timestamp |
| Processing | Extraction, matching, validation, calculations, updates |
| Record of truth | Authoritative record versus site/sheet copies |
| Automation | Safe automatic path versus human review by exception |
| Owner | Who must act next and where the task appears |
| State | Queued, accepted, needs review, failed, completed; evidence of each |
| Recovery | Duplicates, stale data, partial success, retry, and reconciliation |
| UI | What customer/operator sees; efficient next action and clear feedback |
| Acceptance | Observable proof of a correct result, including the failure case |

## Stage sequence

### WF-00 — Data entry and intake

**Roleplay:** a supplier sends today's offer and the operator brings it into the system.

**First question:** How do offers reach you today: Telegram posts/photos,
spreadsheets/manual entry, or a mixture?

**Recorded answer:** the intended primary stream is messages forwarded to the bot,
analyzed and saved to Sheets. A separate source reads a known wholesaler as an anchor
to help extraction. The recovered source identifies a legacy Rezvan Pakhsh PDF input;
the main refresh delegates to a separate server whose source/configuration is not supplied.
Treat reference acquisition as a distinct input stream; it is not another supplier's
offer silently replacing the imported one. Its field authority is still an open decision.

**Further clarification:** the existing process writes into `ReferencePrices`; audit
older GAS and bot iterations before specifying a replacement. The current Python path
does not read that sheet in the reproduced case. `gas-migration-audit.md` records the
history audit; `recovered-gas-audit.md` covers the eight-file user export, reference
contract, 18 offline observation groups and remaining upstream-server/trigger dependencies.

Define authorized submitters, channels, supplier identity, required content, timestamps,
photos, batch boundaries, and acknowledgements. Preserve the original source and its ID.
Try duplicate submission, photo albums/captions, and unclear currency or unit.
**Deliverable:** intake contract and repeat/retry behavior without duplicate manual entry.

### WF-01 — Extraction, normalization, and product identity

**Roleplay:** the system turns raw input into products and supplier-specific offers.

Define name cleanup, per-unit versus per-box amounts, supplier versus consumer prices,
packaging/minimums, and matching an existing product. Distinguish canonical product identity
from a supplier's offer identity. Keep price provenance and history.
Explicitly roleplay how the known-wholesaler reference helps: which product attributes
or price/unit interpretation it supports, how matches are established, and what happens
when it disagrees with the forwarded post. Keep inferred/reference-derived fields auditable.
Try two names for one item, two suppliers, a changed price, and a missing reference price.
**Deliverable:** normalized data contract; create/update/history/quarantine rules.

### WF-02 — Review and correction by exception

**Roleplay:** clear records proceed; only ambiguous/suspicious records reach an operator.

Define checks, review owner/queue, original-post context, correction controls, audit history,
and whether a correction fixes one offer or a reusable rule. Avoid routine re-entry.
Try corrected/skipped offers, an unanswered prompt, and two operators reviewing the same item.
**Deliverable:** review rules, correction trail, and a clear unresolved-work queue.

### WF-03 — Pricing, eligibility, and release approval

**Roleplay:** a clean product becomes a permitted customer selling-price range.

Use the existing price-band rules in `business-flow.md`. Define pricing-setting authority,
reference-price validity, preview of price changes, product minimums, and sellable/held states.
Keep supplier costs/margins internal. A supplier offer does not prove physical stock.
Try thin margins, no valid reference price, stale availability, and a changed pack minimum.
**Deliverable:** pricing/publication policy and authority, with explicit reasons for a hold.

### WF-04 — Website publication and synchronization

**Roleplay:** approved products, settings, prices, and photo bytes reach WordPress.

Define automatic/manual publication, matching/update IDs, fields that can be overridden,
cache refresh, timestamps, withdrawal behavior, and synchronization receipt. Catalog accepted
and images still pending are different outcomes; report them separately.
Try partial success, timeout/retry, duplicate publication, missing photos, and a newly held
product already visible on the site. Do not silently overwrite intentional operator changes.
**Deliverable:** publish/sync contract, receiver confirmation, and failed-work/retry controls.

### WF-05 — Efficient customer discovery and basket UI

**Roleplay:** a household shops on a phone, then test the same tasks on desktop.

Define the homepage promise, search/categories, product/pack/supplier/minimum details,
separate maximum/current discounts, current price, basket progress, subtotal, and savings.
Try first addition at the minimum, cross-product repricing, removals raising other prices,
empty basket opening discounts, target reached, fixed-price products, and failed quote refresh.
**Deliverable:** intuitive desktop/phone screens and honest pricing/error states. Final
production totals remain server-authoritative; this is not purely a visual calculation.

### WF-06 — Receive requests/orders reliably

**Roleplay:** the ready customer basket becomes durable, actionable operator work.

Decide whether submission is a request, reservation, or committed order. Guest name/mobile
is a proposal, not settled policy. Define required details/address timing, request ID,
price snapshot, customer receipt, record of truth, duplicate prevention, and operator routing.
Try double-tap, connection loss after acceptance, missing contact, and notification failure.
**Deliverable:** submission contract, durable receipt, customer acknowledgement, and owned
queue entry. A missed notification must not lose an order; success must not precede storage.

### WF-07 — Order review and supplier/customer coordination

**Roleplay:** an operator checks an incoming household basket with supplier(s).

Define who sells/fulfills the basket, availability/reservation checks, order owner, quote
validity, price-lock timing, response expectations, contact channel, and operator notes/tasks.
Try partial availability, multiple suppliers, price changes, substitutes, and no customer reply.
Reprice any altered basket and obtain approval for its revised total.
**Deliverable:** actionable review queue, change handling, customer approval, confirmed order.

### WF-08 — Payment handoff and fulfillment

**Roleplay:** a confirmed order moves through payment coordination, procurement/preparation,
packing, and delivery.

Defer payment-provider selection in Iran. Decide the operational steps now: payment request
timing, proof of payment, preparation owner, delivery details/cost approval, dispatch evidence,
and customer updates. Do not invent a gateway, fee, or delivery promise.
Try unverified payment, supplier non-fulfillment, partial dispatch, unavailable delivery,
or delivery costs changing the amount the customer must approve.
**Deliverable:** workable first-release manual handoffs and explicit dependencies/owners.

### WF-09 — Keep up with orders and aftercare

**Roleplay:** the operator opens the day's work; a customer asks for an update or change.

Design one daily view of new requests, waiting on supplier/customer/payment, preparation,
dispatch, overdue follow-ups, failed actions, and completed work. Every active order needs
an owner, current state, last update, and next action or due time. Decide guest tracking.
Try cancellation, missed confirmation, delivery issue, missing/damaged goods, and return/refund.
**Deliverable:** lifecycle, follow-up rules, customer messages, support ownership. Existing
code has `new/review/confirmed/cancelled`; richer states are proposals until implemented.

### WF-10 — Reconciliation, reporting, and recovery

**Roleplay:** the operator reconciles a day with an interrupted import or missed update.

Compare source/extracted/reviewed/published counts, site synchronization, durable orders
versus notifications, and accepted/fulfilled/cancelled lines. Decide alerts, recovery ownership,
and safe replay. Define required backup/restore and access ownership.
Measure manual touches, correction backlog, source-to-site delay, order-to-first-response
time, overdue orders, and failed/duplicate work. Set targets with the user, not invented SLAs.
**Deliverable:** daily checklist, exception report, recovery runbook, and agreed measures.

### WF-11 — Whole-stream replay and design acceptance

Replay one supplier offer through a closed household order; then cover small-shop differences
such as quantities, repeat buying, supplier needs, and possible invoices. Test desktop/phone
customer UI and the operator work surface. Use sample data only.
**Deliverable:** confirmed first-release workflow, data/state map, responsible owners,
acceptance criteria, revised mockups, and backlog split into launch essentials, manual
first-release steps, and deferred payment/automation. Visual approval precedes production
redesign. Passing a roleplay does not establish working integrations.

## Starting system map: code evidence, not an approved future architecture

```text
supplier post/photo
  → intake / preserved source (MessageData)
  → extraction / price history (Offers)
  → latest supplier-product rows (Products)
  → exceptions / choices (Decisions, Prompts, optional Items)
  → price-band settings (Shop)
  → publication + photo upload → WordPress/WooCommerce catalog
  → customer basket / server quote
  → durable request → operator / supplier review
  → confirmation → payment handoff → preparation / delivery
  → tracking / aftercare → reconciliation / recovery
```

The uploaded GAS now supplies the `ReferencePrices` writer/reader and AI extraction
consumers that were absent from the 66 inspected commits. It also defines Inventory log
as canonical and builds a fixed-markup storefront sheet, unlike the Python pathway above.
Recover the upstream refresh server and actual installed jobs/bindings, then decide
authority and map preservation before implementation. Do not confuse the extraction
anchor with consumer-price discount calculations or treat either diagram as live proof.

Order/order-line sheets and a WooCommerce order-notification hook exist in code. The
older PHP store has different account/order behavior. Decide authoritative ownership
instead of treating all copies as independent masters. Do not assume that retries,
fullfillment states, synchronization, or a unified work queue already work end to end.

## Scene checkpoint and continuity

For each scene, record the stage contract above, confirmed decision, proposal/open issue,
normal and failure results, customer/operator screen/message, and acceptance criterion.
Update `decisions.md`, `roleplay-state.json`, `project-handoff.md`, and relevant design/
business notes. Refresh the shared snapshot only if publishing succeeds.

Other chats need the shared URL or exported brief and a checkpoint. They do not inherit
this conversation or automatically synchronize decisions. If publication fails, state
which version is live and give the latest checkpoint explicitly.

This end-to-end WF-00…WF-11 sequence supersedes the narrower RP-00…RP-08 scene order.
No earlier scene was completed; confirmed customer-persona decision D-001 remains valid.
`roleplay-plan.md` is retained as a customer-journey appendix, not the active sequence.
