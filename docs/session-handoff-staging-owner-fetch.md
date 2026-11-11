# Session handoff: staging owner-workflow `Failed to fetch`

Updated 2026-11-11 (Asia/Tehran). This is the current operational handoff for the
WordPress owner-workflow browser error. The broader business and pipeline decisions
remain in [`project-handoff.md`](project-handoff.md); the staging-first development and
deployment rule is in [`../AGENTS.md`](../AGENTS.md).

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

## Current authentication blocker

After the user supplied the staging-specific variable names, the previous session
checked both the process environment and the current managed-environment bindings.
Neither `MOLAVISTAGE_ADMIN_USERNAME` nor `MOLAVISTAGE_ADMIN_PASSWORD` was present or
bound. The only admin variables visible were the different `MOLAVISALE_ADMIN_*` pair.
That pair was tried earlier and did not establish a WordPress session; do not retry it
or assume it is the staging account. Avoid more guesses to prevent a login lockout.

The anonymous request to the staging admin URL ended at
`wp-login.php?redirect_to=...&reauth=1`. The TinyFish default browser profile had no
recorded staging sign-in, and its vault run reported no saved staging credentials.
Therefore the last live check did not reach the page JavaScript or any owner workflow
request. The login page itself showed no `Failed to fetch` error. An anonymous POST to
`admin-ajax.php` returned HTTP 400 with body `0`, which is expected without an
authenticated WordPress session because this proxy is registered for logged-in users.

The user stated the staging variables were added after the session started. In the
current runtime they were not hot-loaded: the current environment status was fresh but
did not list either name. A new session must check again before attempting sign-in.

## Next-session checklist

1. **Keep all investigation on staging.** Do not change or deploy to production unless
   the user explicitly names production. The deployment tool defaults to staging; pass
   `--site staging` explicitly for any plugin deployment.
2. **Check variable names only.** Confirm that the fresh runtime exposes both
   `MOLAVISTAGE_ADMIN_USERNAME` and `MOLAVISTAGE_ADMIN_PASSWORD`. Do not print their
   contents. If either is missing, do not fall back to the general `MOLAVISALE_ADMIN_*`
   pair. Tell the user the attached runtime did not receive the staging variables and
   use a secure browser-profile sign-in only if that path is available/authorized.
3. **Sign in once with the staging pair.** Use the normal staging WordPress login and
   preserve cookies for the page check. If authentication is rejected, stop and report
   the visible non-secret error; do not repeatedly retry.
4. **Load the reported page read-only.** Inspect the initial page load and select the
   relevant tab/panel, but do not press buttons that confirm, dismiss, reprocess,
   retry, reconcile, approve, or mutate records. No product, Sheet, order, or queue data
   should change during diagnosis.
5. **Capture only request metadata.** In browser Network/console, record the failed
   request URL/path, method, status, browser-level failure reason, and a sanitized JSON
   `error` code. Do not copy cookies, nonces, auth headers, response business data, or
   credential values into output.
6. **Correlate the browser request with Railway and WordPress logs.** Check whether an
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

No authenticated page inspection or authenticated proxy request has yet been completed
in this incident. No owner workflow action or business-data mutation has been submitted
during the checks recorded here.
