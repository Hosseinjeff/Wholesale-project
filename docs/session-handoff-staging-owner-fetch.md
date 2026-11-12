# Session handoff: staging owner-workflow `Failed to fetch`

Updated 2026-11-13 (Asia/Tehran). This is the current operational handoff for the
WordPress owner-workflow browser error. The broader business and pipeline decisions
remain in [`project-handoff.md`](project-handoff.md); the staging-first development and
deployment rule is in [`../AGENTS.md`](../AGENTS.md).

## Latest: Staging CSS layout repair — 2026-11-13

The owner reported that the dashboard CSS looked badly broken. The live stylesheet had
multiple stale stage-grid rules; its latest declaration forced all six workflow stages
into one horizontally scrolling row and overrode the earlier mobile rules. At a 390px
viewport, the stage grid measured 795px inside a 368px work area.

Commit `b9fefee44cced0281e8d2639e1a2b717c3e35968` removes the competing stage
declarations and defines a three-column desktop grid and two-column phone grid. The
cards wrap within the available width and no longer create the oversized horizontal
stage strip. It was deployed only to WordPress Staging with
`python3 tools/deploy_wordpress_plugin.py --site staging --since b92f45b`. The uploader
read back and verified `includes/owner-workflows.css`; production and Railway were not
changed.

The guarded authenticated page check returned HTTP 200. Computed styles showed three
columns at a 1365px viewport and two at 390px, with the stage grid fitting its container
at both widths. All eight unique owner-workflow GET routes returned HTTP 200 with no
JSON error code and there were no JavaScript errors. Workflow writes were blocked, no
dashboard action was clicked, and no record payload was captured.

## Latest: blank Staging dashboard render fixed — 2026-11-12

The owner reported that the unified dashboard showed
`صف آماده است؛ اقدام‌ها بدون خروج از این صفحه ثبت می‌شوند.` and rendered nothing below.
This reproduced on `admin.php?page=molavi-operations`. The cause was a JavaScript
`ReferenceError` at `owner-workflows.js:2342`: the dismissal-note input bound
`updateDismissalPreview` before the `let` variable was initialized. Dashboard data had
loaded, but rendering stopped before appending the board.

Commit `f9d19e43bb2a2a8bd2c6c81fe5393d3d131b6c8e` moves the callback initialization
before the event bindings and makes the note handler resolve the current callback when
the owner types. It was deployed only to WordPress Staging with
`python3 tools/deploy_wordpress_plugin.py --site staging --since a1e76cb`. The deployer
read back and verified `includes/owner-workflows.js`; no host normalization was
reported. Production and Railway were not changed.

After deployment, one guarded staging sign-in and a read-only page load returned HTTP
200. The ready status was followed by eight board sections, six stage buttons, four
tabs, and one queue selector. All eight unique owner-workflow GET routes returned HTTP
200 with no JSON error code; the AI-confirmations route was requested a second time by
a secondary view. No JavaScript errors or browser-level request failures remained.
The guard allowed the single WordPress sign-in and owner-proxy POST requests whose
embedded method was GET; it blocked every other POST. No workflow write passed, no
queue action was clicked, no direct Railway browser request was made, and no record
payload was captured. Other POSTs and non-staging network requests were blocked by the
browser guard.

This resolves the reported blank dashboard render on Staging. It does not independently
verify every action workflow or the separate `molavi-ai-confirmations` page. If the
owner's existing tab was already open during deployment, refresh the Staging dashboard
to load the updated asset.

## User report and scope

The user reports that the owner workflow fails with `Failed to fetch`, including the
recent-post review/reprocessing tab (`بازبینی و بازپردازش پست‌های اخیر`) and other
workflow functions. The reported staging page is:

`https://staging.molavisale.ir/wp-admin/admin.php?page=molavi-ai-confirmations`

The goal for the next session is to authenticate to **staging**, inspect the page and
identify the failing request without submitting any owner decision or queue action.
The user has said that staging credentials were added after the previous session
started and supplied these environment-variable names:

- `MOLAVISTAGE_ADMIN_USERNAME`
- `MOLAVISTAGE_ADMIN_PASSWORD`

Never print or copy their values into chat, logs, Git, or these notes. Do not request
that the user paste values into chat.

## Deployment and code state already established

The repository branch at the start of this handoff was
`codex/fix-owner-workflow-fetch`, tracking `origin/main`, at commit `7e7b045`.

- `ff540da` routes owner workflow browser calls through the authenticated WordPress
  AJAX proxy `molavi_owner_workflow_proxy` at `admin-ajax.php`. The browser sends a
  same-origin request with WordPress cookies and a nonce. WordPress checks the owner
  capability, nonce, and an explicit backend-path allowlist, then sends the request to
  Railway with the protected bridge token and WordPress actor identity.
- The main workflow client and reference snapshots client use the relative WordPress
  proxy URL and same-origin fetch mode. Relevant source:
  `shop-wp/molavi-shop/includes/owner-browser-api.php`,
  `shop-wp/molavi-shop/includes/owner-workflows.js`,
  `shop-wp/molavi-shop/includes/reference-snapshots.js`, and
  `shop-wp/molavi-shop/includes/reference-snapshots.php`.
- The proxy assets were deployed to the staging cPanel document root
  (`public_html/staging`) with
  `python3 tools/deploy_wordpress_plugin.py --site staging --since 090e7fd` and read
  back successfully. The served JavaScript showed the same-origin proxy configuration
  and no direct staging Railway URL.
- `7e7b045` adds `https://staging.molavisale.ir` to the backend browser-origin default.
  `REFERENCE_BROWSER_ORIGINS` was configured on both Railway environments as
  `https://molavisale.ir,https://www.molavisale.ir,https://staging.molavisale.ir`.
  Production deployment `555811f2-767a-49bf-b579-9843dd8d3704` and staging deployment
  `f7245f2a-b642-45e1-abbd-d371b688edc3` both succeeded. Staging-origin preflights for
  all 13 workflow endpoints passed against both services.
- `302a77c` added distinct staging and production cPanel document roots to the plugin
  deploy tool. `5478c6d` set staging as the default target and recorded the standing
  staging-first user instruction in `AGENTS.md`.

These checks establish that the proxy code was deployed and the tested CORS preflights
pass. They **do not** establish that the authenticated owner page works end to end.
The CORS config is still relevant to any remaining direct browser API routes, but the
owner workflow's intended API path is WordPress `admin-ajax.php`.

## Current staging session and first page check

The current managed runtime has both `MOLAVISTAGE_ADMIN_USERNAME` and
`MOLAVISTAGE_ADMIN_PASSWORD` bound. Their values were checked only for presence and
were never printed. One staging WordPress sign-in succeeded.

The authenticated owner-workbench and AI-confirmation pages both returned HTTP 200,
mounted the owner workflow, and did not show the reported Persian error or `Failed to
fetch`. The first browser capture saw 20 WordPress `admin-ajax.php` POSTs return HTTP
200, but it did not retain enough sanitized request metadata to map each one to its
backend path. This is evidence that the visible error did not reproduce on those two
loads, not an end-to-end confirmation for every workflow endpoint.

**Read-only verification warning:** the existing `dashboard()` function calls
`drainWordPressWorkflowOutbox()` during page load. That function can claim previously
queued supplier-price or Sheet decisions and submit them to Railway. No decision,
retry, reprocess, dismiss, or reconcile control was clicked during the first check, but
the automatic outbox path was not blocked then. Whether a due command was pending or
delivered was not established; do not assert that records were untouched based on that
page load.

For any further browser inspection, allow only WordPress owner-proxy requests whose
inner method is `GET`. Block `molavi_owner_review_outbox_pending`, all
`molavi_owner_review_store` writes, and every owner-proxy `POST`; block navigation to
hosts other than `staging.molavisale.ir`. Log only path, method, HTTP status, browser
failure category, and a sanitized error code. Never print cookies, nonces, auth
headers, credentials, page business data, or response bodies.

### Staging redesign release check

Commit `7d265d1cb5c1740c474594d7e976e6e2c839cbf4` is live in Railway Staging as
deployment `ab1a96c0-2d5a-40bc-b777-ebf4773253bd` (`SUCCESS`) and in the staging
WordPress plugin. The guarded browser check returned HTTP 200 for both pages and for
nine dashboard GET routes plus two AI-confirmation GETs. It displayed four work stages,
three dashboard tabs, and the re-extraction scope/date/channel/group controls. The
reported error and `Failed to fetch` were not visible. Outbox claims, review-store
writes, and workflow POSTs were blocked during this follow-up, so no owner decision or
re-extraction run was submitted by that check. Production was not changed.

The re-extraction “linked” scope means that an eligible source offer references a
`Product ID` present in the current `Products` table. It does not certify that the
WooCommerce item is currently published. Scope, selected channels, and inclusive
Tehran-date boundaries combine with AND logic; group-by channel/day changes only the
preview display. Runs retain the configured model and existing quality checks.

### Staging album OCR and workbench filters — 2026-11-11

Commit `41ac5c25f0e7a3723ece656380eca37ebcad84c9` is live only in Railway Staging as
deployment `a446438e-c61c-4545-86c6-febdbe2b53b3` (`SUCCESS`). The staging service is
pinned to that commit, has one running replica with no reported issues, and `/health`
returned `healthy`. `owner-workflows.css` and `owner-workflows.js` were deployed to the
Staging WordPress root and read back successfully.

The workbench presents each product's primary task and groups related actions. Its
filters combine product/channel search, source channel, operational state, current
catalog status and Tehran-date range. Album rows sharing a Telegram `Media Group ID`
are treated as one source post throughout all, pending, catalog-linked and recent-policy
re-extraction. The server merges distinct captions, downloads every image and asks
Gemini to transcribe them together with image boundaries. Incomplete albums do not
produce partial OCR; extracted offers remain in owner review. Limits are 10 images,
8 MB per image and 12 MB aggregate.

No owner re-extraction was started and no supplier record or owner decision was changed
by this release. Python compilation, JavaScript syntax and `git diff --check` passed; the
changed WordPress static asset was also read from the staging host. No authenticated
page load or owner-proxy API request was performed against commit `41ac5c2`, so the last
authenticated UI evidence is the guarded check on `7d265d1` above. This release alone
does not establish that the earlier `Failed to fetch` report is fixed.

### Ground-up owner dashboard rebuild — 2026-11-12

Commit `14fcd6c205d7685aa0818df5aeaa9ac7a9b7060e` is live only in Railway Staging as
deployment `e7b7a59f-aa16-4175-b5ed-7bbb6f1541a0` (`SUCCESS`). The service is pinned to
that commit, has one running replica and no active warnings or criticals. Its
`/health` endpoint returned `healthy`. The matching WordPress plugin release was
deployed with `tools/deploy_wordpress_plugin.py --site staging --since 41ac5c2`; the
four changed PHP/JavaScript/CSS files were read back and verified against commit
`14fcd6c`.

The workbench now shows six handoffs from Telegram/Railway intake through extraction,
Offer and Product identity, evidence, catalog publication, and Woo storefront. Its
master/detail queue keeps records and a focused primary action together. Combined
filters cover channel, work state, Product ID linkage, message evidence/freshness,
catalog status, Woo outcome, and Tehran-date range. Existing all/pending/catalog-linked
re-extraction scopes and all-images album OCR remain available.

Dashboard initialization now issues queue reads only; saved outbox delivery and
review-context writes require an explicit action. Storefront reconciliation is a
read-only request and stable Product/Offer IDs are required to join records. No
re-extraction, approval, retry, or record-decision action was submitted for this
release. Production was not changed.

This runtime had both staging credential variable names, and their values were never
printed, but it exposed no browser-control tool for the required authenticated page
inspection. The files and Railway health were verified; the rebuilt page was not
visually inspected and the earlier `Failed to fetch` issue is not yet confirmed fixed.
For a future browser check, allow the same-origin `admin-ajax.php` transport only when
its embedded backend method is GET; block all backend POSTs, review-store writes, and
outbox claims. A blanket block of the outer admin-ajax POST would also block read-only
GETs proxied by WordPress.

### Follow-up queue and dismissal redesign — 2026-11-12

The workbench rewrite was initially deployed in commit
`52c4aad64b70414f00444c319d3684f172fa17ca`; the uploader read back and verified
`includes/owner-browser-api.php`, `includes/owner-workflows.css`, and
`includes/owner-workflows.js`. Follow-up source commit `3cb144c` resets the reason field
after each successful batch. Final Staging deploy revision
`0fdd71015b22e2d912b304e2a120e3feeeda72c8` ran with
`python3 tools/deploy_wordpress_plugin.py --site staging --since 14fcd6c`; the uploader
uploaded and verified `includes/owner-workflows.js`, confirmed the PHP and CSS files
were unchanged, and reported no host normalization. No backend code changed, so
Railway was not redeployed; production was not changed.

This follow-up replaces the stage/queue card pile with a compact unique-row list and a
focused selected-record action workspace. The source-to-store journey and supporting
evidence are collapsed by default. The owner-only dismissal taxonomy spans all six
workflow stages, including Railway operations and OCR/media groups, and supports
selected rows, current queue, current stage, filtered selected queue types, and all open
work for selected queue types. Reasons and notes are stored in per-owner WordPress
user meta. Dismissal is reversible and only hides rows from that owner's dashboard; it
does not alter a product, source record, price, publication decision, or worker job.
The dismissed archive filters by stage, queue type, and reason. Bulk previews require
complete auxiliary pagination and large batches are sent in bounded chunks.

`node --check` and `git diff --check` passed. PHP CLI is unavailable. This runtime had
no browser-control tool capable of blocking owner-proxy POSTs before an authenticated
page load, so the deployed layout and live dismissal behavior were not visually
verified. Do not claim the earlier `Failed to fetch` issue is fixed from this release.

### Railway queue visibility follow-up — 2026-11-12

After the owner reported no visible queues, a staging sign-in succeeded once using the
staging-specific environment variables; their values were not printed. The operations
page returned HTTP 200. Read-only requests to all eight owner workflow GET routes also
returned HTTP 200 with no JSON error code. Only path, method, status, and error-code
metadata were retained; response records were not exposed. The displayed payload was
not recorded, so this does not confirm whether the live queues contain open items.

Code review found that Railway operations were nested under both the system view and a
collapsed queue details section. It also found that the intake badge counted state
subtotals without falling back to the API's `actionable_count`. Commit `a1e76cb` adds a
direct intake entry point, shows that actionable count, and opens the queue details
when intake is selected. It is deployed to WordPress Staging at revision
`a1e76cbcf273786ffafaa985a4cfd3b20b1fbd3b`; the uploader verified read-back of
`includes/owner-workflows.js` with no host normalization. Production and Railway were
not changed.

No browser-control tool was available to inspect the logged-in rendered page. If the
owner still sees no work after selecting the Railway intake entry, the rendered page
and its current route need a guarded browser inspection before another source change.

## Next-session checklist

1. **Keep all investigation on staging.** Do not change or deploy to production unless
   the user explicitly names production. The deployment tool defaults to staging; pass
   `--site staging` explicitly for any plugin deployment.
2. **Check variable names only.** Confirm that the fresh runtime exposes both
   staging-specific names. Do not print their contents or fall back to the different
   `MOLAVISALE_ADMIN_*` pair. The sign-in already succeeded once in the current runtime.
3. **Guard page loads before inspection.** Block the outbox claim/delivery path, all
   review-store writes, and all owner-proxy backend `POST`s before opening any owner page.
   No page load should submit a decision, queue run, retry, or reconciliation.
4. **Capture only request metadata.** Record the backend route path, method, status,
   browser-level failure reason, and sanitized JSON `error` code. Do not copy cookies,
   nonces, auth headers, response business data, or credential values into output.
5. **Correlate the browser request with Railway and WordPress logs.** Check whether an
   `admin-ajax.php` request with action `molavi_owner_workflow_proxy` reached WordPress,
   and whether the matching backend route reached Railway. Check staging cPanel/PHP
   errors if the WordPress proxy itself returns a gateway error.

## Failure-code guide

The proxy implementation in `owner-browser-api.php` yields useful distinctions:

- No request or browser `Failed to fetch` before an HTTP status: inspect the served
  script/config, WordPress cookie/session behavior, browser blocking, and whether the
  live page is still running an old cached script. Confirm the requested origin is the
  staging origin.
- `403 owner_required`: the session user lacks the expected owner capability.
- `403 invalid_nonce`: stale/missing nonce, cached page, or session mismatch.
- `400 workflow_route_unavailable`, `invalid_query`, or `invalid_body`: proxy route or
  request construction mismatch.
- `502 upstream_unavailable`: WordPress could not complete its server-to-server call
  to Railway; inspect staging PHP/cPanel outbound connectivity and logs.
- `502 invalid_upstream_response`: Railway returned an unusable status/body to WordPress.
- Backend `401`/`403`: inspect protected bridge-token configuration, actor/owner check,
  and route scope without printing token values.
- Backend `404`: compare the requested path with the deployed backend and the proxy's
  allowlisted paths.

The proxy requires protected WordPress constants for the Railway base URL and bridge
token (`MOLAVI_REFERENCE_SERVER_URL` and `MOLAVI_REFERENCE_BRIDGE_TOKEN`). Never put
their values in Git or session notes. The AJAX handler only exposes logged-in owner
routes; the previous anonymous `400`/`0` does not test the authenticated path.

## Change and verification discipline

If evidence points to a source bug, make the code change in
`shop-wp/molavi-shop/`, review and run the focused checks for the affected workflow,
commit and push, then deploy only to staging with
`tools/deploy_wordpress_plugin.py --site staging`. Read back the changed remote files
and verify the logged-in page and failed request again. Do not call an end-to-end fix
complete based only on CORS preflight or anonymous HTTP checks. Preserve any existing
owner decisions and avoid reprocessing supplier posts during verification.

An authenticated staging sign-in and page inspection succeeded earlier in this incident;
the guarded check on commit `7d265d1` blocked owner-proxy writes and extraction actions.
The latest follow-up on `41ac5c2` used static asset reads and Railway health/status only;
it did not load an owner page or submit an owner workflow action. The earlier unguarded
dashboard load still leaves the previously recorded possibility that an already-pending
outbox command was delivered.
