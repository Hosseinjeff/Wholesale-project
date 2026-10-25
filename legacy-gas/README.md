# Recovered Apps Script source

`recovered-2026-10-25/` preserves the eight original JavaScript files from the
user's `gas.rar`, received 2026-10-25 (Asia/Tehran). `manifest.json` records archive
and member SHA-256 hashes. The directory date is receipt time, not a source revision.

This directory is the **source of truth for the user-supplied GAS export**. For that
snapshot use these eight files, not the older root `google_apps_script.js`. It is not
proof of the currently deployed GAS version. Keep the original files unchanged;
record any later export as a separate dated snapshot with its own hashes.

These files are an **audit snapshot**, not a selected deployment or an executable
migration. Nothing imports them into the Python application. Do not replace the
root `google_apps_script.js`, run setup/refresh/replay functions, or disable GAS
based only on this archive. Compare behavior before choosing a future writer.

The archive contains no `appsscript.json`, installed-trigger list, deployment list,
or Script Properties values. Source alone cannot confirm which functions are live,
the configured refresh-server URL, AI mode/provider, or the actual script timezone.
No fetched Git history object exactly matched any of the eight uploaded files.

Audit and preservation map: `docs/recovered-gas-audit.md` and
`docs/recovered-gas-inventory.md`. Run the isolated, offline reproductions with:

```bash
node tools/audit_recovered_gas.cjs
```

The user authorized committing and pushing this snapshot for future-chat access.
The website handoff contains audit documentation; the GitHub repository preserves
the actual source. Configuration values and deployment bindings were not supplied.

## Read only what the task needs

Start with this README and `docs/recovered-gas-inventory.md`. Search the relevant
function/file and read a bounded range rather than loading all eight files or asking
for the archive again. Reuse recorded evidence for unchanged code; rerun or expand
checks when a change or unresolved question justifies it. The user requests economical
tool output and context use; make no numerical usage-cap promises.

- Reference refresh, matching and scale inference: `30_reference_prices.js`.
- Inventory mapping and price confirmation: `20_inventory_reference_matching.js`.
- AI batching/extraction: `60_extraction_ai.js`.
- HTTP handlers: `10_google_apps_script.js`; config/schema: `00_core_config_helpers.js`.
- Storefront selection/pricing: `40_storefront.js`; ingestion: `50_ingestion_batches.js`.
- Sheet setup/diagnostics: `70_sheets_admin_debug.js`.
