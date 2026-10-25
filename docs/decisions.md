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
