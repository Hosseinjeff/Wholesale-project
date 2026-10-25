# GAS → bot migration audit

Revision 4.0. Audited 2026-10-25, Asia/Tehran. Checkout: `1b58f55`.
Status: repository history and supplied GAS export audited; migration **not signed off**.
No production logic, sheet data, bot settings, or storefront code was changed by this audit.

**New evidence:** the user supplied `gas.rar`: eight files / 176 named functions,
including the missing reference writer, reader and extraction consumers. See
`recovered-gas-audit.md` for the recovered contract, feature preservation map and
18 offline observation groups. The separate refresh-server source and actual trigger/
deployment bindings remain missing. Findings below retain their original repository
scope; they are not claims that the supplied export lacks reference functionality.

## The requirement that prompted this audit

The user states that a known-wholesaler reference process writes to `ReferencePrices`
and helps correctly extract forwarded supplier posts. This is an existing process to
locate and preserve, not a new reference-source idea to invent. The request also requires
auditing the GAS-to-bot transition for lost behavior and remaining transition paths.

`ReferencePrices` must not be confused with the consumer/reference selling-price field
used in basket discounts. The uploaded source now supplies schema, writer/reader,
matching and trigger definitions. Installed triggers, upstream refresh implementation
and deployment bindings still need verification.

## Scope and evidence

- Fetched all published GitHub branch heads; searched 66 reachable commits, including
  12 revisions of `google_apps_script.js`. No `ReferencePrices` reader/writer or exact
  sheet-name reference was found in those revisions. Current source searches also found none.
- Examined pre-migration GAS (`5bbe791`), the service-account migration (`54dd4e8`),
  offer-history/current-book change (`f761452`), operator review changes, and current code.
- Ran the five existing Python suites: **64 tests passed**, with HTTP calls blocked.
- Compared eight extraction fixtures between historical GAS in an isolated Node VM and
  current Python. Product counts and selected price fields matched for these fixtures;
  metadata changed in three fixtures. This is limited fixture parity, not exhaustive equivalence.
- Reproduced eight operational behavior groups using only in-memory stores and mocked
  HTTP/publication/notification; see findings below.
- Tried one read-only request for `ReferencePrices` metadata through the deployment URL
  recognizable in the configured GAS value. It returned HTTP 404/non-JSON. No setup,
  refresh, replay, cleanup, or other mutating GAS endpoint was invoked.
- Direct Sheets read-back is blocked in this session: the configured service-account
  value fails JSON parsing. This does not establish that Railway's separate value is invalid.
- Inventoried all 67 named top-level GAS functions in `gas-migration-inventory.md`,
  including legacy diagnostics/administration. Trigger bindings outside the repository
  and externally maintained GAS projects are outside the recovered evidence.

Absence in Git history did **not** prove the user's reference process did not exist.
Its source is now recovered from the uploaded archive. See the supplementary audit
before choosing a writer or retiring any GAS dependency.

## Migration chronology

| Revision | Change | Audit implication |
|---|---|---|
| `5bbe791` | Last substantive pre-port GAS extraction/diagnostic revision | Differential baseline |
| `54dd4e8` | Python extraction and direct service-account sheet writes | Core migration boundary; GAS fallback retained |
| `f761452` | Append-only Offers + rebuilt latest Products; GAS labeled legacy | Intentional data-model change; legacy writer still callable in its source |
| `b3a9d61`, `36f36bc` | Buying desk, decisions, bot correction prompts | New exception-review workflow |
| `6b3237d` | Product names separated from carton text; volume/price-type data | Intentional metadata changes, not lost parsing |
| `7b62379`, `a50995e` | Bot pushes catalog and uploads image bytes | New publication handoff, requiring partial-success visibility |

## Findings and required disposition

### A-01 — ReferencePrices integration absent from the inspected Python path

**Priority:** launch blocker for claiming reference-assisted extraction parity.
**Evidence:** `catalog/extract.py:736`, `catalog/sync.py:585`; history search described above.
An in-memory `ReferencePrices` sheet was seeded and all reads tracked: the pipeline read
MessageData/Offers/Products/Items/Decisions/Prompts/Shop/logs, but never `ReferencePrices`.
The seeded schema was synthetic; no real reference data or prices were inferred.

**Disposition:** source gap partly closed by `gas.rar`; current Python integration
is still missing. The recovered inventory covers writer/consumers/schema/units/matching/
fallbacks/operator corrections; refresh-server source and installed bindings remain open.
Port or explicitly retain each contract and address B-01…B-08 before sign-off. Add golden
fixtures that fail if reference data is ignored; do not recreate it from guesses.

### A-02 — Duplicate guarantees differ by path and by batch ID

**Priority:** high. **State:** reproduced.
Historical GAS `checkForDuplicates` checks message ID/content in full single-message mode;
GAS message-only/batch modes already skip that check. Current `catalog/sync.py:490` appends
raw messages; offer deduplication at `:1545` depends on a batch-scoped Offer ID.

Replaying the same batch/message yielded 2 raw rows and 1 offer. Replaying the same source
message under another batch yielded 3 raw rows and 2 offers overall. Therefore same-batch
offer retry safety is preserved, but stable-source update idempotency is not established.

**Disposition:** define a durable source key (including platform/chat/message/revision as
appropriate), distinguish edits from retries, and test cross-batch redelivery. Do not describe
this solely as a lost GAS feature: the GAS batch path already had weaker duplicate handling.

### A-03 — Failed extraction can acknowledge success and trigger publication

**Priority:** high. **State:** reproduced, inherited across the migration boundary.
`catalog/sync.py:490` returns `status=success`, `ack=ingestion_complete`, while
`extraction_status=error` and `rollback=true` after forced extraction failure.
`app.py:132` checks only top-level success and attempts publication. GAS `finalizeBatch`
has the same split acknowledgement pattern; this is an inherited contract ambiguity.

**Disposition:** distinguish durable input receipt from successful extraction/review and
publication eligibility. Suppress publication on extraction failure/rollback, keep retryable
state, and report the failure to the operator. Existing rollback itself restored the price book
in tests; do not claim it destroys old prices.

### A-04 — Webhook success precedes durable storage

**Priority:** high. **State:** source-confirmed durability risk, not crash-injection tested.
`app.py:58` buffers messages in process memory and uses daemon timers/threads. The webhook
acknowledges a queued message before a sheet write. A restart during this window has no
durable local queue to recover from; one configured worker does not remove that window.

**Disposition:** persist an intake receipt/outbox or equivalent durable queue before final
acceptance; explicitly reconcile received versus finalized batches after interruption.

### A-05 — GAS fallback can still write the old Products model

**Priority:** high. **State:** source-confirmed retained transition path; deployed binding unverified.
`app.py` selects the GAS writer when service-account configuration is absent. The retained
GAS `doPost/importProductData` updates Products directly and does not maintain the Python
Offers/current-book/review contracts. The label "legacy" does not disable those functions.

**Disposition:** make writer selection explicit, validate configuration, and choose one owner.
Retire/restrict legacy mutation and reprocessing only after external GAS/reference consumers
are inventoried. A fallback is not semantically equivalent merely because it returns HTTP 200.

### A-06 — Ingestion pause and last-batch controls were not equivalently ported

**Priority:** medium. **State:** source-confirmed operational difference.
GAS exposes `INGESTION_ENABLED`, pause/resume and script-property last-batch status. Python
has BatchStatus rows and health checks, but no equivalent observed global intake-pause
control or same diagnostic contract. Old control URLs cannot be assumed to pause Python.

**Disposition:** agree an authenticated intake/recovery control and operator visibility;
retire misleading old controls or label their writer scope clearly.

### A-07 — Photo publication can report pushed while incomplete

**Priority:** medium. **State:** reproduced.
With catalog acceptance followed by a mocked image-sync failure, `catalog/publish.py:145`
returned `ok=true`, `pushed=true`, `images_left=1`. Catalog acceptance is real; this does
not establish that images completed. `_publish_shop_quietly` does not surface this result.

**Disposition:** separate catalog and media statuses, keep resumable work, and expose retry/
failure information. Do not roll back valid catalog data merely because an image is pending.

### A-08 — Order handoff can acknowledge without a sheet write

**Priority:** high. **State:** reproduced plus source-confirmed integration gap.
With `USE_SHEETS=false`, `/shop/order` returned HTTP 200, `status=success`, `written=false`.
The `notified` count is attempted recipients, not verified delivery: `send_chat_text` does
not return a delivery receipt. The WooCommerce hook marks `_molavi_published` on any <300
response, checks no explicit `written` acknowledgement, and has no durable retry recorded
in this hook. It is attached to classic `woocommerce_checkout_order_processed`; block-checkout
coverage is unverified. The mock operation did not submit any real order.

**Disposition:** define durable acknowledgement, idempotent retries/outbox, delivery status,
and coverage of the actual checkout path. Keep failure visible in the operator queue.

### A-09 — Stock status can be lost on the site handoff

**Priority:** high. **State:** reproduced for payload; receiver behavior source-confirmed.
A synthetic imported product marked `Out of Stock` was emitted by `site_products` as
`status=sell`, without a stock-status field. `shop-wp/molavi-shop/includes/sync.php:248`
unconditionally sets synchronized products `instock`. Review/skip status is not stock state.

**Disposition:** define inventory authority, carry explicit stock/withdrawal state, and test
out-of-stock updates end to end. Existing extraction of an out-of-stock phrase does not prove
the customer site will stop selling it.

### A-10 — Legacy GET administration and maintenance need retirement/access review

**Priority:** high if the retained source is deployed publicly; live exposure unverified.
**Version boundary:** this finding concerns the root/history handler. The uploaded export
adds token guards; see B-08. Do not apply the old-handler claim to every GAS version.
GAS `doGet` dispatches setup, cleanup/clear, pause/resume, and reprocessing functions without
an authorization check visible in the inspected handler. Read/export helpers can expose
project data under that deployment's access policy. No mutating URL was called in this audit.

**Disposition:** inventory deployments, owners, triggers and scripts before disabling them;
protect or remove unsafe administration paths. Keep necessary anchor refresh jobs explicitly.

### A-11 — Configuration readiness is presence-based, not validated

**Priority:** medium. **State:** source-confirmed; invalid credential parse reproduced locally.
`sheets_configured` tests variable presence; key parsing occurs later in `build_store`.
This session's configured service-account string fails parsing, so a "ready binding"
does not establish usable Sheets authorization. The configured GAS URL probe returned 404.

**Disposition:** validate selected writer at startup and expose its functional readiness;
do not silently switch to a semantically different writer after a runtime failure.

### A-12 — Parser parity preserves known limitations; it is not correctness proof

**Priority:** medium. **State:** eight-fixture differential comparison.
BONAKDAR/TOP_SHOP/NOBEL/universal cases retained selected prices and product counts.
Three fixtures changed metadata (price type, volume, or cleaned carton/name) in Python,
consistent with later named commits/tests. Those are not automatically regressions.

Both implementations returned no products for the tested universal Persian-digit/thousands-
separator sample. The NOBEL sample containing two priced blocks returned only the first
product in both; an existing test explicitly expects that behavior. These limitations require
golden examples and reference-assisted validation, not blind migration parity.

**Disposition:** preserve intentional metadata improvements and define business-correct
golden outcomes for multi-product posts, Persian numerals, unit/carton ambiguity, missing
reference matches, and reference/source conflicts before changing the parser.

## Preservation/transition matrix

| Behavior | Current finding | Required action |
|---|---|---|
| Main known-channel/universal parsers | Selected parity, limitations remain | Expand golden coverage using recovered anchor rules |
| Source/raw message fields | Preserved, media added | Add stable idempotent intake identity |
| Offer history/latest book | Intentional Python enhancement, tested | Keep Offers/Products ownership consistent |
| Failed-write retries and rollback | Present; existing tests pass | Repair acknowledgement/publication semantics |
| Exception review/prompts | Python and recovered GAS have different contracts | Reconcile prompts versus confirmation requests and anchor provenance |
| ReferencePrices writer/consumer | Recovered from gas.rar; absent in current Python | Recover upstream server/bindings, then choose preservation/ownership |
| Ingestion pause/last-status helpers | GAS controls retained, Python differs | Decide supported authenticated control surface |
| GAS direct mutation and maintenance | Retained transition code | Inventory bindings, then restrict/retire deliberately |
| Photo/catalog sync | New feature with partial-success ambiguity | Separate receipt/completion statuses |
| Durable order notification | Incomplete acknowledgement/retry contract | Add recoverable outbox/receiver evidence |
| Stock propagation | Lost at publication boundary in reproduced case | Define authority and explicit stock updates |

## Reproduce the local checks

```bash
python tools/audit_gas_migration.py /tmp/molavi-migration-audit
node tools/compare_gas_extraction.cjs /tmp/molavi-migration-audit
```

Python needs the project's runtime dependencies. Node uses built-in modules only.
The audit harness blocks HTTP, uses MemoryStore, and mocks order/publication paths.
Its JSON is a finding reproduction, not a green health verdict. Existing suites were
also run with HTTP blocked: catalog, inbound, pricing, publish, and storefront (64 tests).

## Closure checklist

- GAS source recovered from `gas.rar`; obtain refresh-server source, manifest metadata,
  actual spreadsheet/deployment bindings and installed triggers.
- Record field schema, upstream wholesaler reader, schedule/freshness, normalization,
  match rules, field authority, inference provenance, conflicts, and operator decisions.
- Map every recovered anchor behavior to preserved/changed/missing/retired status.
- Agree and repair A-02…A-12 by priority; retain reproducible failing cases as regression tests.
- Verify one writer and all dependent jobs before retiring any legacy GAS deployment.
- Re-run historical/current/anchor golden cases and the complete supplier-to-order replay.
- Only then sign off migration completeness. This report intentionally does not do so.
