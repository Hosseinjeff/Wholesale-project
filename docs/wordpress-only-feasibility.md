# Removing Railway from the Wholesale application

Assessment: 2026-11-17, Asia/Tehran. Scope is `Hosseinjeff/Wholesale-project`, on
production and Staging. The separate `Hosseinjeff/FollowUP-bot` service is another
application; its resource usage is not removed by retiring Wholesale.

The application can be implemented entirely as WordPress/PHP work. This host cannot
run the existing Python application unchanged: authenticated capability checks on
both sites found SQLite3 support and 512 MB PHP memory, but no `proc_open` or usable
Python executable at the checked system paths. Real minute cron is proven on both.
Moving database files does not replace the remaining Python program.

| Work | Current execution | Requirement for a WordPress-only installation |
|---|---|---|
| Storefront, favorites/saved carts, cart/checkout, Woo products/orders | WordPress/WooCommerce | Already local; preserve the site's current plugin version. |
| Durable queue, operational/reference ledgers and publication/activation locks | PHP state host after verified per-environment cutover | Keep private files outside the web root and include them in host backups. No Railway replica. |
| Due-work checks, clock ownership, outbox detection and payload housekeeping | WordPress runtime/state host | Native PHP checks and compaction need no executor wake. |
| Owner dashboard data shaping and workflow commands | Python endpoints in `app.py`, querying WP state | Port read models and commands into the existing owner plugin, preserving revisions and audit receipts. Keep batching; no per-product HTTP reads. |
| Telegram/Bale intake, bot buttons, enrollment and prompt resolution | Python webhook handlers and `catalog/bot_ui.py` | Native WP webhook with secret validation, commit-before-acknowledgement, callback signatures and direct short responses. Queue long work. |
| Source validation, AI/OCR extraction and product normalization | `catalog/gemini.py`, `catalog/extract.py`, `catalog/sync.py` | Gemini's HTTP API is callable from PHP. Port the normalization/validation rules and fixtures; the AI API itself needs no Railway. Process bounded jobs through real cron. |
| Reference fetch/change comparison, matching and approvals | `reference_import/*`, Python matcher | Implement API fetch/change detection and ranking locally. Preserve stable source IDs, incremental price/title behavior and current decision authority. PDF support is a separate legacy caller decision. |
| Sheet synchronization, freshness/conflict rules and Woo publication projection | Python Sheets/OAuth and publisher code | Native PHP OAuth/Sheets adapter plus current revision checks. Keep manual Sheet edits and idempotent Woo effects consistent. |
| Inventory, procurement, clearance and fulfillment planning | Python domain code and WP-owned stores | Port the calculations/commands with their existing receipts and business rules; Woo remains the order/payment owner. |
| Old public quote/catalog and media proxy endpoints | Compatibility Python routes | Audit callers, then retire unused paths or provide the needed WP route. Do not rebuild obsolete parallel interfaces. |

## Completion gates

Keep the existing PHP plugin as the unified host. Move complete domains to it rather
than create another dashboard or a second business store. Preserve canonical IDs,
source revisions, pricing/stock policies and the accepted roleplay decisions. Compare
the PHP results against existing fixtures and read-only/shadow evidence before changing
the writer for each domain. The existing legacy suites contain known stale expectations;
passing transport tests alone is not proof of extraction or fulfillment parity.

Railway can be stopped only when WP owns the bot webhook, all runnable job types,
review writes, reference refresh, Sheet/Woo effects and any still-used public routes.
Prove duplicate delivery, retries after interruption, lease recovery and reference/source
change races on Staging, then production. Confirm no pending work or active caller still
depends on Railway. Keep the current small on-demand executor until those gates pass.
An idle-memory reset and a state-host move are not a claim that the Python runtime has
already been replaced.
