# Supplier AI extraction evaluation

The AI-only production path uses `catalog.gemini.extract_message_products`. The
owner's operational reconciliation report does not call Gemini; it reports queue,
review, Sheet and Woo outcomes without treating them as an accuracy score.

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

**Current measurement:** not run. The November 2 audit supplied no owner-labelled
corpus to this workspace, and no Gemini requests have been made for this evaluation.
