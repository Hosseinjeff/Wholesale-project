# Staging release: code reference matching and text-only intake

Verified 2026-11-17, Asia/Tehran. Scope is Staging under the standing deployment
instruction. Production was not changed.

## Released source

- Backend commit: `466c71d80f9eef0117284ee600626b7fe035cc16` on
  `codex/free-google-staging-release`, pinned in Railway.
- Railway deployment: `622f5bb8-e8ea-425e-b8ef-1b43649a1b26`, terminal `SUCCESS`.
- Project `b693c2b6-6d01-4930-87ab-8a722542b4c0`, Staging environment
  `da7513fc-47f3-42f9-91dc-c3827895b49f`, service
  `ca660193-e903-4617-a247-b6057ceacd9c`.
- WordPress `includes/owner-workflows.js` uploaded to `staging.molavisale.ir`
  through the tracked deployer. The UAPI read-back and served asset match the
  release bytes: SHA-256 `6a2aa41bc09b9b038ccaf38c95636c112cc11fe19a6b9e47533e085677bb82fe`.

The release is based on the current Staging line at `6e6da4c`, preserving its newer
storefront/dashboard, incremental reference refresh, WordPress state provider and
memory-reclamation work. It does not replace that line with the older main-branch UI.
The pending binary state transport correction is included. Quota tables/cache use
the authoritative WordPress pipeline store in WordPress-state mode; compressed
cache values travel as scalar text rather than binary SQL parameters.

## Verified behavior

- `/health` returned HTTP 200, `healthy`, version
  `2026-11-17-free-routing-text-only`.
- Native Staging owner sign-in and the owner page returned HTTP 200. The newly
  provided staging credentials are available; the old missing-credentials blocker
  is no longer current. Do not print credentials, cookies, nonces or bridge values.
- A direct authenticated backend preview returned HTTP 200, `ok: true`,
  `image_extraction_enabled: false`, `media_review_total: 0`.
- A synthetic request to the retired image extraction action returned HTTP 410,
  `image_extraction_disabled`. No source ID from the business was submitted.
- Photo-only messages are attachments. Text/captions remain extractable; images,
  original source records and historical audit/transcript columns are preserved.
- Exact compatible identities and trusted approved mappings avoid embedding/model
  calls. Numeric contradictions and final explicit-attribute checks run in code.
- 213 focused extraction/reference/queue/state/memory tests and 40 publication,
  operations and photo-publishing tests passed. The synthetic Chromium dashboard
  suite passed on desktop/mobile, with no browser errors. Those test writes were
  intercepted synthetic requests, not live business decisions.

Railway reports no pending patch, warnings, crashes or recent failures for the new
deployment. Sleep mode remains enabled. Do not infer sleeping from configuration
alone or keep waking the service with repeated health probes.

## Outstanding work

1. **WordPress owner proxy connectivity:** the authenticated same-origin preview
   returned HTTP 502, while the identical direct backend preview succeeded. The
   inspected Railway HTTP log contained no proxy preview request at that point.
   The failure is on the WordPress-to-Railway transport path; a specific transport
   error was not recovered from the available cPanel logs. This remains the active
   owner-workflow investigation, not an authentication or OCR feature failure.
2. **Free model routing activation:** capability is deployed, but the project's
   real per-model RPM/TPM/RPD values, quota sharing and task qualification must be
   configured before enabling it. No allowance or paid eligibility was guessed.
3. **Matching quality and integration:** evaluate actual Persian examples for
   accuracy/latency, then connect optional semantic retrieval/model comparison to
   the owner workflow using saved results and existing approval/freshness policies.
   These services currently have explicit bounded Python/CLI entry points.
4. **End-to-end business acceptance:** after proxy repair, check text/caption intake,
   reference assignment, owner price review and guarded publication on Staging.
   No real approve/dismiss/reprocess/reference-activation action was submitted here.
5. **Production promotion:** requires an explicit production instruction under
   `AGENTS.md`. The tested backend remains pinned so documentation/frontend pushes
   cannot silently redeploy it.

No live Google inference request, supplier-price decision, reference activation,
catalog replay, customer/order mutation or production deploy was performed during
this release verification.
