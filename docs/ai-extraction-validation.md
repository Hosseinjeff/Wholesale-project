# Supplier AI extraction evaluation

The AI-only production path uses `catalog.gemini.extract_message_products`. The
owner's operational reconciliation report does not call Gemini; it reports queue,
review, Sheet and Woo outcomes without treating them as an accuracy score.

## Current prompt revision and card audit — 2026-11-03

The prompt now explicitly handles parenthetical named-flavor lists and says not to infer
supplier prices from consumer prices, carton counts or expected discounts. It also maps
explicit `اتمام موجودی` / `ناموجود` statements to the source post's stock status while
warning that a historical post does not establish today's stock. The local prompt assertion
and adapter suite passes 47 tests. The earlier small live smoke check below predates this
revision and does not validate it.

A read-only dashboard audit covered 26 open cases across 14 saved source posts. It found
one omitted noodle flavor, one omitted cake flavor, one missing size-specific sale amount,
several extracted prices without matching supplier-price labels, two old out-of-stock
statements that the extraction left unknown, and one package total whose division matched
the staged unit amount. The WordPress cards now surface these mismatches and group identical
same-price flavor cases. The owner decisions and product records were left untouched.

No saved supplier source was sent to Gemini during this audit. Automatic review blocked a
one-off request because it would transmit a private saved post to Google's Gemini API; no
payload was sent. Validation after this prompt revision is local only until a safe, approved
live sample is available.

Use `tools/evaluate_supplier_extraction.py` with an owner-labelled JSONL file kept
outside Git. Each line contains one saved source message and its verified expected
products:

```json
{"channel":"@supplier_channel","text":"<private supplier post>","expected":[{"name":"<exact product title>","price_role":"package","price_amount":240000,"package_count":24}]}
```

Expected `price_role` is `unit` or `package`. `price_amount` is the supplier amount
in that explicitly stated basis. Include `package_count` when the source establishes
one. Use exact source titles for `name`; do not normalize away size, flavor or pack
markers. Include only source messages whose expected answers have been checked by an
owner against the original post and, where needed, its supplier reference.

Run a small cost-bounded sample first, then the complete labelled set:

```sh
python tools/evaluate_supplier_extraction.py /private/path/molavi-extraction-golden.jsonl --max-cases 3
python tools/evaluate_supplier_extraction.py /private/path/molavi-extraction-golden.jsonl
```

Each case makes one Gemini request using the configured production extraction adapter.
The tool prints aggregate exact identity, source price amount, unit/package basis,
package count and extra-product counts only. It does not print names or source text and
does not write to Sheets, WooCommerce or the durable queue. Gemini usage may incur API
charges. Do not commit supplier posts, owner labels, API keys or case-level outputs.

**Small live smoke check (2026-11-03):** after the prompt update, three owner-approved
saved supplier posts were sent through the production Gemini model and the local
normalizer: one noodle post with an unlabeled comma-separated flavor list, one labeled
flavor list with carton quantity, and one single-supplier-price post without a consumer
price. They produced 5, 7, and 1 records respectively; all explicit supplier and
consumer prices and package counts matched, the missing consumer price stayed empty, and
none of the 13 records was held for review. The noodle post had exposed a real boundary
failure: the prior prompt/model found the flavors and prices, but local title-scoped
evidence stopped at the standalone flavor list. The prompt now names that post shape and
the normalizer carries the uniquely matched list into the same product's price context.
Source messages and row-level output were not saved in the repository.

**Formal accuracy measurement:** not run. The live check is a narrow smoke test, not an
owner-labelled corpus or a statistical accuracy score.
