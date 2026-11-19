# Free Google quota routing and reference suggestions

Updated 2026-11-19, Asia/Tehran. Free routing was verified at `b95e93b`,
Railway deployment `36ca155e-c0b3-487a-a385-84737ef10bbf`. The currently deployed
staging bot is `b909736`. Its WordPress activity response showed quota totals as
unavailable during the November 19 check. The owner supplied the model limits
below for the earlier verified release. Staging allocations leave room for other
project traffic, including the production executor.

| Model | Google project RPM / TPM / RPD | Staging RPM / TPM / RPD |
| --- | ---: | ---: |
| Gemini 3.5 Flash-Lite | 15 / 250,000 / 500 | 3 / 50,000 / 50 |
| Gemini 3.1 Flash-Lite | 15 / 250,000 / 500 | 12 / 200,000 / 450 |
| Gemini Embedding 001 | 100 / 30,000 / 1,000 | 80 / 27,000 / 950 |

## November 19 provider checks

With the workspace project key, Gemini 2.5 Flash returned a Google-grounded shopping response with one cited source. It also extracted the correct unit, carton, and consumer amounts from a synthetic Persian listing. One ambiguous catalog comparison returned a valid no-match response. Another returned incomplete output under the existing matching request settings. Gemini 2.0 Flash and 2.5 Flash-Lite returned HTTP 404 for grounding. Gemini 3.5 Flash-Lite returned HTTP 429 with a billing-access message when grounding was enabled. Attempts to qualify 3.6 Flash and 3.8 Flash ended in timeouts or provider unavailability, so they have no production allocation.

The Search grounding rows in AI Studio describe the tool. Extraction and catalog matching require the underlying model’s active RPM, TPM, and RPD limits. The workspace service account received HTTP 403 when reading Cloud Quotas. Keep the last verified model allocations until the active AI Studio model rows are available. [Google rate limits](https://aistudio.google.com/docs/rate-limits) · [Google pricing](https://aistudio.google.com/docs/pricing#pricing-for-tools)

Google schedules API requests to `gemini-2.5-flash` to move to 3.5 Flash-Lite on December 1, 2026. Its Search grounding terms forbid collecting grounded links for crawling or indexing. Scheduled product price discovery uses the separate market-search integration, and WordPress verifies prices on the retailer pages it can access. [Google deprecations](https://aistudio.google.com/docs/deprecations#gemini-2.5-flash-models) · [Google Search grounding terms](https://aistudio.google.com/docs/terms)

### Gemma catalog matching pilot

Both Gemma models can enter the catalog matching pilot. The 26B model previously returned overload errors and later selected the correct Persian SKU in 1.45 seconds. The 31B model selected the correct Persian SKU and abstained when flavor was unspecified. The `generate_choice` mode uses a schema of allowed catalog choices and minimal thinking. Code resolves proven identities first and checks the suggestions against explicit facts and the active snapshot.

Shortlists of at most four text-supported candidates prefer `gemma-4-26b-a4b-it`. Broader shortlists and candidates found only through semantic retrieval prefer `gemma-4-31b-it`. The other Gemma model can serve the same request if its allowance is available. The verified Gemini pool remains the fallback and continues handling text extraction. Gemma candidates with temporary failures re-enter selection after their cooldown. A separate Gemma grounding probe returned no cited retailer source. [Gemma API models and tools](https://gemma.ai/deployment/gemini-api/)

`GOOGLE_FREE_MATCH_MODELS` can add a match-only pilot without replacing existing `GOOGLE_FREE_MODELS` entries. The staged pilot budgets both Gemma models together at 2 requests and 30,000 input tokens per minute and 8 requests per day. This is a local trial cap. Use the model quota rows from AI Studio before raising the allocation or treating them as separate provider budgets.

## Verified staging allocation

Both Flash-Lite models passed the synthetic extraction and reference comparison
checks, including an explicit no-match case. The Embedding 001 allocation reserves
50 requests for owner review search and allows up to 900 index requests per day.

An owner-authenticated staging request started the durable index for the 3,394-row
active Rezvan reference. Live synthetic extraction through the staging quota ledger
returned HTTP 200 on both configured Flash-Lite models. The first 27 vectors
were verified in the WordPress cache. The owner-authenticated
`POST /pipeline/runtime` action `start_google_reference_index`
starts or resumes the job. `GET /pipeline/runtime?google_reference_index=1` reports
its coverage, state, and next due time. A worker revalidates the active reference,
then processes one slice of up to 32 rows and queues the next slice. Saved vectors
are reused across quota waits and reference price changes. WordPress wakes the
executor when work is due. Production uses the existing model configuration.

The owner uses Google's free API tier and wants independent model allowances used
efficiently. `catalog/google_quota.py` accepts owner-verified project/model limits;
there are deliberately no hard-coded 500/day or 1,000/day assumptions. Google's
active AI Studio quota rows remain the authority. A consumer Gemini subscription
is distinct from the API project's tier.

## Configuration and boundaries

Routing is disabled until `GOOGLE_FREE_ROUTING=1`. It additionally requires
`GOOGLE_QUOTA_PROJECT` (the project identity, not a key), an absolute persistent
`GOOGLE_QUOTA_DB_PATH` in local-state mode, and `GOOGLE_FREE_MODELS` containing a JSON array. Each entry
requires:

- `model`, `tasks` (any qualified subset of `extract`, `match`, `embed`).
- Explicit positive integers `rpm`, `tpm`, `rpd` from the project's quota table.
- `api`: `generate`, `embed`, `decide`, or `generate_choice`. Embedding models
  support `embed`. Decision and constrained choice generation models support `match`.
  Standard generation models can extract text or match.
- `free_tier_verified: true` and `qualified: true`, set only after verifying the
  actual project's free access and evaluating the specified task on reviewed data.
- Optional `bucket` for aliases/shared quotas (default: model name), and
  `reserve_rpd` to protect foreground work from background indexing.

With `PIPELINE_STATE_HOST=wordpress`, quota tables and the compressed text cache use
the existing authoritative pipeline store through `catalog.state`. Only its empty
`/tmp` marker exists on Railway; the separate quota path is not used. No new state
transfer or competing business database is introduced. Cache parameters use scalar
text so they work through the WordPress transaction transport.

Different models have independent buckets by default. Aliases that Google counts
together must share a bucket and identical limits. Only qualified models can receive
a task. A match-only or extraction-only model takes the task first when its allowance
is available. Mixed-task models remain available as fallback and balance by fraction
of daily capacity used, breaking ties in configuration order. Matching tries
constrained-choice models first and then other API types in configuration order.
An explicit model override can only select a configured qualified model.

The API key must belong to the verified **Free-tier API project**. Google enforces
billing and actual quotas; local counters cannot turn a billing-enabled project into
a free project or see requests sent by other clients. There is no paid endpoint,
automatic billing enablement, API-key rotation or project rotation in this code.
Other clients must use the same ledger or receive allocations whose sum fits each
actual model limit. In particular, separate production and staging WordPress ledgers do not
share counters merely because `GOOGLE_QUOTA_PROJECT` is identical: allocate their
budgets explicitly, or use one authoritative executor. Never configure each isolated
runtime with the entire same-project allowance.

## Runtime behavior

Owner review now offers an explicit **request reference suggestion** action for AI
confirmation and reference-item cases. It queues one idempotent `reference_suggest`
task in the existing WordPress-owned durable pipeline. The worker reads the current
offer and eligible active snapshot, applies code-first matching, and only then uses
configured qualified Google matching models. Optional semantic retrieval uses
`GOOGLE_REFERENCE_EMBEDDING_MODEL` and vectors previously built by the bounded index
command. Enabling an embedding model does not embed the whole reference catalog on
a page load. Indexing remains an explicit bounded operation.

The worker discards results if the case, owner decision, reference eligibility,
snapshot, or routing policy changes. GETs only attach saved receipts and candidates
in one bulk lookup; they never call Google or start the worker. Suggestions do not
approve links, choose a price role, change prices, or publish products. Quota waits
use the existing WordPress due-work schedule. The owner refreshes to see results;
there is no background browser poll.

Owner-workflow reads are serialized in the browser because each Railway request
calls back to WordPress for state. Parallel proxy requests can occupy the hosting
PHP workers and starve these callbacks until the 45-second proxy timeout. Writes
retain their explicit action path. This preserves one Railway worker and idle sleep.
Read-only gateway failures (502/503/504) receive one serial retry after one second;
subsequent failure remains visible. Owner writes are never automatically retried by
this browser path. AI-confirmation and price-ledger schema setup use the existing
schema cache rather than replaying unchanged DDL on every owner read.
AI review requests decision history only for reviewable offers and visible cases,
instead of the entire completed offer archive. Owner GETs have a bounded 90-second
upstream timeout for state round trips and executor startup; POSTs retain 45 seconds.
Supplier-price evidence also loads and observes sources in bounded groups of 300,
preserving manual verification, source revisions and original timestamps. It no
longer makes separate remote state transactions for every product.

SQLite transactions reserve capacity before HTTP requests across concurrent callers.
The ledger survives restarts, groups all task usage for each model, enforces rolling
minute request/input-token limits and Pacific-calendar daily limits, including DST.
Text reservations use a conservative UTF-8 byte bound plus framing allowance; successful
Google input-token usage reconciles the reservation. Image extraction is disabled;
models cannot be configured with an OCR task.

Failed/unknown requests are not refunded. Structured daily 429s block that bucket
until midnight Pacific. Minute or unknown 429s create a bounded bucket cooldown.
An overload, transport failure, or invalid constrained choice can briefly pause the
specific model. A 404 pauses the endpoint for 15 minutes. Other qualified models can
use their remaining allocation, including a working alias in the same bucket. An explicit
model pin still applies. Exhaustion returns `QuotaDeferred`
with the next retry time instead of sleeping. Existing durable intake/re-extraction
jobs retain that due time and do not consume failure retries. WordPress's established
due-work scheduler can wake the on-demand executor then. No new idle process or poll
was added. A rolled-back batch reuses validated cached AI extraction results; explicit
owner re-extraction still makes a new extraction. Cache data stays private in the configured state store.

## Reference matching

`reference_import/ai_matching.py` provides explicit bounded operations:

1. Resolve an exact compatible identity or a supplied approved mapping in code, with
   zero Google requests. Compare equivalent explicit measurement units in code and
   respect both `variant` and the catalog's `variation_type` field.
2. Exclude explicit numeric size/count contradictions in code. If no candidates remain,
   return review instead of asking a model to guess.
3. Optionally index Rezvan identity descriptions using one configured embedding model. Price-only
   changes reuse vectors. Indexing stops at the requested count or quota and can resume.
   Indexing and search read cached vectors from WordPress in bounded groups. Search generates a
   query embedding only when at least one compatible reference vector is available.
4. For unresolved identities only, query cached vectors, keeping only the leading results in memory. Every embedding
   model/dimension/task format has its own cache namespace; vectors never mix models.
5. Combine semantic and existing lexical candidates using reciprocal rank fusion in code.
6. Ask a qualified Decision or generation model for an existing reference ID or
   no-match result. Invalid, incomplete and invented-ID responses are rejected.
7. Check the selected candidate's explicit brand, variant, count and measurements in
   code. A conflict becomes review, never a model override of the stated facts. Brand
   or flavor aliases that code cannot verify remain for owner review.
8. Cache the result against the snapshot, product, shortlist, model pool and policy.

Without Google routing/credentials, exact suggestions and the lexical review shortlist
still work. Approved overrides must come from the existing eligible-snapshot owner
ledger, never from untrusted product text. Embedding cache format is unchanged by this
matching change, so existing valid vectors do not need to be regenerated.

Outputs are **suggestions only**. They do not approve products, choose unit/package
prices, change supplier values, write Sheets or publish Woo products. Existing owner
mapping, freshness, source and 5% reference-price policies remain authoritative.
Embedding availability is optional: when no vectors exist or embedding quota is
waiting, the existing lexical shortlist remains usable, with the retrieval wait exposed.

Dashboard reads attach saved suggestions. An explicit owner request queues matching
against the eligible active snapshot. The CLI also supports bounded indexing and
suggestion runs. Its caller supplies the eligible snapshot because
`coverage_verified` alone does not establish activation, freshness or owner
permission to use a stale snapshot.

Run from the repository after configuring verified budgets:

```sh
python tools/free_google_reference.py status
python tools/free_google_reference.py index --snapshot work/active-reference.json --embedding-model gemini-embedding-001 --limit 100
python tools/free_google_reference.py suggest --snapshot work/active-reference.json --product work/product.json --embedding-model gemini-embedding-001
```

`status` makes no Google request. `suggest` can run without free routing or a key for
the code paths above; omit `--embedding-model` in that mode. Ambiguity remains a review
shortlist. Indexing and unresolved AI suggestions consume the configured free allowance
and return a resumable due time on quota exhaustion (exit code 75). Keep
input files and output suggestions private; they can contain supplier data.

## Text-only intake — owner instruction, 2026-11-17

The owner explicitly removed OCR from the workflow. Supplier extraction reads only
message text and captions. Photos remain available as product/source attachments;
photo-only messages create no extraction task or media review queue. A message that
needs product facts must supply them as text or a caption.

The image reader, its download helper, the OCR model task and dashboard controls were
removed. The retired owner API action returns HTTP 410, and previously queued image
jobs finish as `image_extraction_disabled` without reading the source or calling a
model. Old media flags cannot add image text to a caption extraction. Recent-post
reprocessing excludes photo-only sources. `attachment_only` is a completed intake
state, separate from product approval and owner rejection.

Historical image transcript columns and owner audit records are retained as evidence;
new extraction writes no transcript. Rezvan acquisition still uses its Store API.

## Qualification before enabling

Use reviewed Persian examples spanning aliases, reordered names, missing attributes,
no-match cases, conflicting flavors, packaging levels and decimal sizes such as `1/2ل`.
Measure candidate recall, wrong-identity rate, review workload and latency for each
task/model. Qualify models independently; a high quota does not establish quality.
Decision 1 is optional pending real project availability and this evaluation. Local
tests use synthetic HTTP responses and cannot establish live Persian accuracy.

## Local verification

All 221 focused extraction, quota, code matching, reference intake, durable handoff,
publication, on-demand runtime, owner API, media and AI confirmation tests pass.
Coverage includes zero-model exact matches, explicit attribute conflicts, no image
reader, caption-only extraction, preserved photo attachments, no photo-only model
request, retired API rejection and old image-job retirement. The owner workbench
interaction test and JavaScript syntax checks pass. Review fixtures use current
source evidence or explicit owner price verification; production freshness policy
is unchanged. The browser transport fixture records each concurrent request's own
proxy receipt. No Google or live business data was used for verification.

Official references: [active quotas](https://aistudio.google.com/rate-limit),
[quota rules](https://ai.google.dev/gemini-api/docs/rate-limits),
[embedding spaces and task formats](https://ai.google.dev/gemini-api/docs/embeddings),
[Decision API](https://ai.google.dev/gemini-api/docs/decisions-api).
