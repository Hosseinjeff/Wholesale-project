# Reference server migration — local review draft

Baseline: 2e32dea / D-008. Local branch: reference-dashboard-draft. Required GAS
functionality remains server-owned; originals in legacy-gas are unchanged. This
slice is not full migration parity, an approved mockup or a production deployment.

## Confirmed decisions

- D-009: each stale-reference or failed-refresh occurrence asks the business owner
  to pause/review or continue with the last successful snapshot and a warning.
  Affected automatic interpretation waits for the choice; silence is not consent.
- D-010: reference snapshots reach the age cutoff at 24 hours.
- D-011: decisions belong in the private WordPress dashboard, integrated with the
  existing MolaviShop/WooCommerce plugin and native WordPress access controls.

## Implemented locally

reference_import/importer.py explicitly downloads the PDF, requests Gemini output
and validates a 21-column candidate without writing Sheets or activating data.
The prompt preserves the recovered two-product-block/carton/retail-hint contract.
Candidates include source URL, PDF hash, model, extraction timestamp and row count.
Local candidate creation is atomic and refuses to overwrite an existing file.

Supplier messages now default to Gemini extraction using `GOOGLE_API_KEY`, with the
recovered `AI_API_KEY` accepted for compatibility and `AI_MODEL` defaulting to
`gemini-3.1-flash-lite`. `EXTRACTION_MODE=regex` selects the existing parser. Failed
or unusable Gemini responses fall back to regex; uncertain price roles, package counts,
and low-confidence results remain marked for review. The PDF importer reads the same
key/model defaults. A local crash-aware worker now schedules it every 24 hours by
default, records refresh failures for owner review, and only stages candidate data.
Activation remains a dashboard action. These paths have only been tested with mocked
Gemini responses; no live request or PDF refresh occurred.

reference_import/decisions.py stores per-occurrence choices in SQLite, with owner
allowlists, snapshot scope, exact-retry handling and conflicting-choice rejection.
reference_import/workflow.py applies the 24-hour cutoff.
reference_import/dashboard_api.py provides a private authenticated WSGI bridge.
The existing MolaviShop entry point loads includes/reference-decisions.php, a native
WooCommerce submenu using WordPress login, capability checks and event-bound nonces.
No server secret is placed in browser HTML. Configuration is denied by default.

## Verification

45 focused offline tests pass, including server API authorization, restart/retry
behavior, conflicting choices, snapshot scope, candidate validation and provider
failure behavior. Three canonical price/parser fixtures compare to preserved GAS;
two fixtures document intentional one-unit and inconsistent-unit divergences.
Normalization is excluded from differential equivalence and remains provisional.
Run: python -m unittest discover -s tests -p 'test_reference*.py' -v

PHP 8.3 syntax checks pass for both affected plugin files in an ephemeral container
with the repository mount read-only and container networking disabled. Native WordPress runtime checks now pass as recorded below; browser compatibility
and third-party plugin interactions remain unverified. Read-only live wp-admin login
and installed-plugin inspection succeeded; see reference-dashboard-integration.md.

## Important remaining work

- The persisted-failure lookup and all-live-event assessment are implemented. The
  local PDF/Gemini worker records failures and stages candidates; opt-in durable
  supplier intake uses the gate and records the selected snapshot. Production
  consumers must read via active_reference_use.
- Immutable candidate staging, explicit owner review and atomic activation are
  implemented locally in snapshots.py. Expected-active checks reject concurrent
  replacements, older snapshots cannot replace newer ones, previous data is retained,
  and retired incidents reject late decisions. Live activation is not installed.
- Bind actual approved WordPress owner identities and dedicated bridge secrets.
  Credentials being available does not automatically grant new decision permissions.
- Native PHP admin rendering, owner checks, nonce failure and HTTP receipt/failure
  handling passed in disposable WordPress 7.1.3 + WooCommerce 11.2.1. Browser layout
  and interactions with Elementor/Wordfence remain unverified.
- Verify active reference job/model/settings/timezone and real PDF output quality.
  No Gemini/PDF/Sheets write or job installation was performed.
- Port matching, overrides and item-scoped review; reconcile canonical inventory
  and customer-price authority without importing known legacy defects.

No complete WF scene, GAS retirement, live parity, production installation, push or
public brief update is claimed. Mockup v2 remains unapproved. The earlier Git access
block is resolved by enabling the executor's network permission.

## Latest runtime evidence

46 assertions pass under real, disposable WordPress 7.1.3 and WooCommerce 11.2.1
with synthetic users and a mocked server transport. Full MolaviShop entry point is
loaded. Containers use an internal Docker network with no host ports; no live
credentials or production data are passed in. Test database/container/network are
removed afterwards. See reference-dashboard-runtime-evidence.txt.

Run tools/download_reference_dashboard_runtime.py followed by
tools/test_reference_dashboard_runtime.py. Default assets go under ignored work/.
Downloads use the configured proxy/trust; Docker must be available. Do not supply
production credentials. Current local validation passes 235 offline Python tests and
93 WordPress assertions; neither proves live end-to-end refresh or production readiness.
