# Free Google quota routing and reference suggestions

2026-11-16, Asia/Tehran. Local implementation; no Google calls, live configuration,
deployment, reference activation or owner decision was performed for this change.

The owner uses Google's free API tier and wants independent model allowances used
efficiently. `catalog/google_quota.py` accepts owner-verified project/model limits;
there are deliberately no hard-coded 500/day or 1,000/day assumptions. Google's
active AI Studio quota rows remain the authority. A consumer Gemini subscription
is distinct from the API project's tier.

## Configuration and boundaries

Routing is disabled until `GOOGLE_FREE_ROUTING=1`. It additionally requires
`GOOGLE_QUOTA_PROJECT` (the project identity, not a key), an absolute persistent
`GOOGLE_QUOTA_DB_PATH`, and `GOOGLE_FREE_MODELS` containing a JSON array. Each entry
requires:

- `model`, `tasks` (any qualified subset of `extract`, `ocr`, `match`, `embed`).
- Explicit positive integers `rpm`, `tpm`, `rpd` from the project's quota table.
- `api`: `generate`, `embed`, or `decide`. Embedding models support `embed`; Decision
  models support `match`. Generation models can extract, transcribe or match.
- `free_tier_verified: true` and `qualified: true`, set only after verifying the
  actual project's free access and evaluating the specified task on reviewed data.
- Optional `bucket` for aliases/shared quotas (default: model name), and
  `reserve_rpd` to protect foreground work from background indexing.

Different models have independent buckets by default. Aliases that Google counts
together must share a bucket and identical limits. Only qualified models can receive
a task. Routing spreads generation work by fraction of daily capacity used, breaking
ties in configuration order. Matching tries configured API types in configuration
order, allowing Decision capacity and generation capacity to complement each other.
An explicit model override can only select a configured qualified model.

The API key must belong to the verified **Free-tier API project**. Google enforces
billing and actual quotas; local counters cannot turn a billing-enabled project into
a free project or see requests sent by other clients. There is no paid endpoint,
automatic billing enablement, API-key rotation or project rotation in this code.
Other clients must use the same ledger or receive allocations whose sum fits each
actual model limit. In particular, separate production and staging volumes do not
share counters merely because `GOOGLE_QUOTA_PROJECT` is identical: allocate their
budgets explicitly, or use one authoritative executor. Never configure each isolated
runtime with the entire same-project allowance.

## Runtime behavior

SQLite transactions reserve capacity before HTTP requests across concurrent callers.
The ledger survives restarts, groups all task usage for each model, enforces rolling
minute request/input-token limits and Pacific-calendar daily limits, including DST.
Text reservations use a conservative UTF-8 byte bound plus framing allowance; successful
Google input-token usage reconciles the reservation. Image payloads are deliberately
over-reserved and may need a larger configured TPM budget; do not mark a model OCR
qualified until this behavior is measured against its allowance.

Failed/unknown requests are not refunded. Structured daily 429s block that bucket
until midnight Pacific; minute/unknown 429s and 503s create a bounded cooldown. Each
qualified bucket is tried at most once per call. Exhaustion returns `QuotaDeferred`
with the next retry time instead of sleeping. Existing durable intake/re-extraction
jobs retain that due time and do not consume failure retries. WordPress's established
due-work scheduler can wake the on-demand executor then. No new idle process or poll
was added. A rolled-back batch reuses validated cached AI extraction results; explicit
owner re-extraction still makes a new extraction. Cache data stays private on the volume.

## Reference matching

`reference_import/ai_matching.py` provides explicit bounded operations:

1. Index Rezvan identity descriptions using one configured embedding model. Price-only
   changes reuse vectors. Indexing stops at the requested count or quota and can resume.
2. Query cached vectors, keeping only the leading results in memory. Every embedding
   model/dimension/task format has its own cache namespace; vectors never mix models.
3. Combine semantic and existing lexical candidates using reciprocal rank fusion.
4. Ask a qualified Decision or generation model for an existing reference ID or
   no-match result. Invalid, incomplete and invented-ID responses are rejected.
5. Cache the result against the snapshot, product, shortlist, model pool and policy.

Outputs are **suggestions only**. They do not approve products, choose unit/package
prices, change supplier values, write Sheets or publish Woo products. Existing owner
mapping, freshness, source and 5% reference-price policies remain authoritative.
Embedding availability is optional: when no vectors exist or embedding quota is
waiting, the existing lexical shortlist remains usable, with the retrieval wait exposed.

The new reference service is available through explicit Python/CLI tasks. It is not
installed as an automatic reference worker or added to dashboard GET requests. A
dashboard integration should consume saved suggestions, with mockup review for any
new UI, and obtain the active snapshot through `active_reference_use` before scheduling
work. The CLI trusts its caller to supply that eligible snapshot; `coverage_verified`
alone does not establish activation, freshness or owner permission to use a stale one.

Run from the repository after configuring verified budgets:

```sh
python tools/free_google_reference.py status
python tools/free_google_reference.py index --snapshot work/active-reference.json --embedding-model gemini-embedding-001 --limit 100
python tools/free_google_reference.py suggest --snapshot work/active-reference.json --product work/product.json --embedding-model gemini-embedding-001
```

`status` makes no Google request. Index/suggest explicitly consume the configured free
allowance and return a resumable due time on quota exhaustion (exit code 75). Keep
input files and output suggestions private; they can contain supplier data.

## Qualification before enabling

Use reviewed Persian examples spanning aliases, reordered names, missing attributes,
no-match cases, conflicting flavors, packaging levels and decimal sizes such as `1/2ل`.
Measure candidate recall, wrong-identity rate, review workload and latency for each
task/model. Qualify models independently; a high quota does not establish quality.
Decision 1 is optional pending real project availability and this evaluation. Local
tests use synthetic HTTP responses and cannot establish live Persian accuracy.

## Local verification

All 26 new tests pass. The combined extraction, quota, reference intake/matching,
durable handoff, publication, on-demand runtime and AI confirmation run passes
174 of 177 tests. The three AI confirmation failures also reproduce on unchanged
baseline `076fee3`: fixed-date supplier posts now trigger the current-source
verification rule. They are not relaxed by this change. The other 142 focused
tests pass as a group. Python compilation, CLI help and `git diff --check` pass.

Official references: [active quotas](https://aistudio.google.com/rate-limit),
[quota rules](https://ai.google.dev/gemini-api/docs/rate-limits),
[embedding spaces and task formats](https://ai.google.dev/gemini-api/docs/embeddings),
[Decision API](https://ai.google.dev/gemini-api/docs/decisions-api).
