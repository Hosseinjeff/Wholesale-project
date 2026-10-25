# Recovered GAS export: ReferencePrices and migration differences

Revision 4.0. Inspected 2026-10-25, Asia/Tehran. Input: user-uploaded `gas.rar`.
This supplements the Git-history audit in `gas-migration-audit.md`.
**Source recovered; deployment and migration sign-off remain open.** No live function,
sheet mutation, AI request, supplier fetch, bot message, or storefront change was made.

## Updated interpretation — D-008

The user describes Rezvan Pakhsh data interpreted with a Google API. The recovered
PDF → Gemini → ReferencePrices path matches that description. Active execution remains
unverified, but this is a concrete implementation available for server migration.
The separate delegated refresh wrapper's presence does not establish a configured or
running refresh service. Its upstream source is a required dependency only if actual
configuration/use proves that path matters. Earlier unconditional closure wording is
superseded by this clarification; do not stall the migration on a presumed service.

## What was recovered

Eight JavaScript files, 302,570 uncompressed bytes, **176 named top-level functions**.
Original files and hashes are preserved in `legacy-gas/recovered-2026-10-25/`.
Archive SHA-256: `bb8d09dab050b6af07bee81dd7d55a1c5c9ef2cf1d6395a605516278d085cefe`.
The receipt date does not establish the files' original revision or deployment date.

None of the eight exact Git blob hashes appears in the previously fetched history.
Compared with the root GAS file, 62 function names overlap, 114 are additional, and
five old names are absent. Name overlap does not prove equivalent behavior. The
complete structural inventory and feature dispositions are in `recovered-gas-inventory.md`.

The earlier statement was limited to GitHub: it contains a GAS implementation, but
the searched revisions did not contain this reference system. The supplied archive
does contain it. Do not keep asking the user for source that has now been provided.
The user subsequently authorized preserving this snapshot in GitHub as the source of
truth for future inspections. Use the repository's `legacy-gas/README.md` entry point;
the original eight files remain unchanged. This does not select a deployment.

## Recovered operating stream

```text
External reference-refresh server (implementation/configuration not supplied)
   ↑ GAS daily handler delegates: source=store, sync=true
   ↓ authenticated replace/append callback
ReferencePrices (21 columns)
   ↓ matching, reference/history/category price-role assessment
Forwarded messages → MessageData → AI batch extraction → Inventory log
   ↓ uncertainty                            ↓ confirmed offers + owned movements
MessageData confirmation_requests          storefront sheet
   ↓ operator confirmation                  ↓ integration to live shop unverified
reusable reference-match override / unit-or-package correction

Alternative legacy reference input: Rezvan Pakhsh PDF → Gemini → ReferencePrices
```

The source calls `Inventory log` canonical for supplier offers and owned inventory
(`70_sheets_admin_debug.js:64`). Current Python instead maintains Offers/Products,
Items, Decisions, Prompts and Shop. These are competing data/operation contracts;
matching function names or a successful HTTP response does not reconcile them.

## Reference acquisition and stored fields

`30_reference_prices.js:64` reads `REFERENCE_REFRESH_SERVER_URL` from Script Properties.
It calls that server with `source=store&sync=true` and an admin token. It does not fetch
the store catalog itself or write the returned data locally. `handleReplaceReferencePrices`
and `handleAppendReferencePrices` accept authenticated reference rows and write the sheet.
The server implementation, actual URL, pagination, product mapping, unit conversion,
callback destination and refresh atomicity are **not in the archive**.

`refreshReferencePricesFromPdfLegacy` uses
`https://rezvanpakhsh.com/pdf/price-list.pdf`; it sends the PDF to Gemini. Its prompt
distinguishes two product blocks per PDF row, product codes, carton counts and retail
`ف` hints. For a known count it derives per-unit price from the carton total. This
identifies the legacy PDF wholesaler; it does not prove the delegated Store API source
is the same or that either path is currently scheduled.

`00_core_config_helpers.js:325` defines the 21 headers:

```text
Product Name | Clean Product Name | Normalized Key
Reference Package Price | Reference Unit Price | Consumer Unit Price
Package Count | Unit Label | Volume | Weight | Set Count | Variant | Brand | Category
Raw Name Details | PDF Row Number | Product Code | Currency
Source | Source URL | Last Updated
```

Writer stamps `TOMAN`; supplied inputs are not independently currency-validated by
this writer. The reader uses Product Code, then Normalized Key as reference identity.
Consumer Unit Price is stored but the inspected batch price-role resolver uses reference
unit/package prices. Do not silently turn that field into the customer selling-price
authority or substitute wholesaler prices for the actual supplier's advertised amount.

## Matching, uncertainty and operator handling

- Composite matching weights: name .45, brand .14, category .12, variant .10,
  package count .07, volume .06, weight .06. Only available attributes contribute to
  the denominator; the acceptance threshold is .35. Scores are heuristics, not
  calibrated probabilities. A conflicting attribute is a penalty, not a hard veto.
- A persisted `REFERENCE_MATCH_OVERRIDES` mapping can select a reference with score 1.
  Its key includes normalized name, brand, category and variant; it is stored in
  Script Properties and is not included in this export's values.
- Ambiguous supplier price can be interpreted as unit or carton when within 35% of a
  direct reference and sufficiently nearer one role (distance margin .15). The actual
  supplier number remains the basis of the resolved prices.
- Category fallback uses at least three references and a 40% tolerance. Confirmed
  inventory-history fallback uses recent matching rows and a median; the AI caller
  allows one history row, while another validation caller defaults to two.
- Explicit message/operator price roles take precedence over this scale inference.
  That precedence does not by itself validate currency or a large price change.
- Unresolved extraction creates confirmation requests with source message, proposed
  prices, reference/history context, component scores and product index. The AI queue
  records `pending_ai → processing → ai_processed/not_product/needs_confirmation`.
  Error paths attempt to reset pending rows. Recovery after process termination is
  not established solely by these source-level resets.
- Inventory records include anchor type/ID/name/score, detected price, source dates,
  supplier/message identity and extraction state. Preserve their meaning when mapping
  into Python's offer/review model; do not copy columns without their contracts.

Sources: `30_reference_prices.js:448,545,585,611,663,922`,
`60_extraction_ai.js:628,841`, `20_inventory_reference_matching.js:550`,
`10_google_apps_script.js:1401,1461`.

## Feature preservation / disposition

| Recovered behavior | Current Python checkout | Required disposition |
|---|---|---|
| External Store API reference refresh and replace/append | No equivalent found | Recover upstream source/configuration; retain existing job until ownership is selected |
| Legacy PDF/Gemini reference importer | No equivalent found | Audit actual use; keep an explicit fallback only if needed |
| ReferencePrices schema, matching, reusable overrides | Not consumed by inspected pipeline | Port or intentionally retain, with provenance and golden outcomes |
| AI extraction via Gemini/OpenRouter, durable sheet-backed pending states | Regex channel/universal parsers; no equivalent AI path found | Choose supported extraction mode and preserve uncertain/multi-product behavior |
| Unit/carton assessment from reference, category and confirmed history | Quality/history checks and unit-price prompts, different contract | Map semantics and corrections; preserve useful behavior without reproducing known defects |
| Confirmation requests and reference-match confirmation | Python expects `prompts`, not `confirmation_requests` | Implement an explicit adapter or one supported operator workflow |
| Inventory log supplier offers plus owned movements | Offers/Products current book; no equivalent owned-stock ledger found | Select authority and define migration, identities, quantities, costs and reconciliation |
| GAS storefront fixed markup | Python/Woo basket price bands | Resolve price authority; do not replace the user-required basket discount model with this older rule |
| Admin/read/ingestion/sheet-mutation tokens | Different Python endpoints/configuration | Align authenticated contracts; do not drop guards to make a legacy fallback work |
| GAS schedules, queue reset/pause and diagnostics | In-memory Python intake plus different status surfaces | Inventory installed jobs and choose recovery/control ownership |

The GAS storefront defaults to 20% markup on owned inventory, 15% on supplier offers,
rounded upward to 1,000 toman (`40_storefront.js:302`). These are source defaults,
not newly approved business rules or the same formula as basket discounts.

## Reproduced concerns in the recovered code

**B-01 — References have timestamps but no freshness gate.** An otherwise identical
reference dated 2000 still resolved the synthetic 2026 carton price automatically.
There is no observed TTL in the inspected matching/scale path. Define stale/missing/
partially refreshed behavior before relying on automatic resolution. (R05)

**B-02 — Brand conflicts do not veto an automatic price-role match.** Same-name,
same-category/count fixtures with different brands still resolved as a carton using
the conflicting reference. Persisted overrides also bypass rescoring with score 1.
Define incompatible attributes, ambiguity/ties, and override invalidation. (R06–R07)

**B-03 — Refresh success does not establish refreshed sheet data.** A mocked HTTP 200
HTML response produced `status=success`. The actual daily handler delegates writing
to the separate server and does not inspect a row/version receipt. A scheduled
execution finishing is not proof of a successful, complete reference snapshot. (R10–R11)

**B-04 — Unit/carton confirmation has message-wide scope.**
`applyPriceConfirmationToWaitingInventory` matches message ID, without product index,
name or waiting-state filtering. Confirming the ambiguous tea also repriced the already
confirmed rice in the same synthetic message. Select one offer/product and reject stale
or already-resolved actions. The reference-match confirmation has a request index,
but that does not fix the separate price-role handler. (R14)

**B-05 — Selected supplier and cost can disagree.** With supplier A at 50,000 and a
later supplier B at 80,000 for the same synthetic product, `rebuildStorefront` selected
A but used B's unit cost, giving 92,000 under its default markup. The code selects the
best offer, then derives price fields from the latest product offer separately. Carry
one selected offer's supplier/cost/provenance consistently. (R15)

**B-06 — Inventory eligibility fields are inconsistent.** The mapping drops product
`status`, so waiting/review products get default `Status=for sale`. The storefront
helper does exclude extraction strings containing `waiting`, so this does **not** prove
every waiting product becomes visible. But `OK?=false` / a review warning with an
otherwise confirmed extraction state passes that helper. Agree one explicit eligibility
contract and enforce it at all publication boundaries. (R16, R18)

**B-07 — Recovered GAS is incompatible with the existing Python fallback contract.**
The export requires `INGESTION_ENDPOINT_TOKEN`. The inspected Python fallback batch
payload contains no token. It expects `prompts` for notifications, while this GAS path
returns `confirmation_requests`. Re-enabling fallback is not a safe automatic fix for
the missing reference path. Token rejection was reproduced with a synthetic payload;
actual deployed writer and settings remain unverified. (R17; `app.py:121,151`)

**B-08 — Legacy security finding must be scoped to the version.** A-10 applies to the
root/history GAS handler, which lacked visible guards. This recovered export adds
admin/read/ingestion/mutation token guards. Do not describe those versions as equally
unguarded. Token values and installed deployment access were not supplied. These
guards do not constitute a complete security review of every handler/logging path.

## Offline evidence and remaining closure

`node tools/audit_recovered_gas.cjs` compiles the eight original files together and
runs **18 observation groups**, using synthetic inputs and local Sheets/Properties/
HTTP/trigger doubles. No provider or production endpoint is exposed to the VM. Results
are in `recovered-gas-evidence.json`. The assertions confirm observations, including
defects; passing them is not a health verdict or complete semantic equivalence.
The earlier 64 Python tests and eight historical-parser fixtures remain historical
evidence; they were not rerun or expanded into end-to-end live validation by this audit.

Still required before sign-off:

1. If actual configuration/use establishes the delegated refresh path, locate that
   service's source and its safe configuration
   metadata (URL/owner/source, never token values). Audit extraction, units, full-snapshot
   replacement, callback authorization and failure handling.
2. Obtain `appsscript.json` or equivalent project timezone/scopes/library metadata,
   installed trigger function names/schedules, and active deployment bindings. The
   source defines daily reference refresh around 04:00 in **script timezone** and a
   five-minute AI queue trigger; neither is proven installed. Do not recreate them yet.
3. Select one canonical catalog/inventory and pricing/publication owner. Record intended
   changes versus lost functionality; reconcile IDs, review data and historical offers.
4. Carry forward useful capabilities and explicitly address A/B findings with business-
   correct multi-product, pack/unit, freshness/conflict, duplicate and failure cases.
5. Replay supplier intake through operator review, site publication and a durable order
   using the household first, then small-shop roles. No full scene is complete yet.
