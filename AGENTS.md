# MolaviSale continuity

For the business workflow and migration audit, start with `docs/project-handoff.md`.
The user-provided GAS export is preserved in `legacy-gas/recovered-2026-10-25/`;
`legacy-gas/README.md` explains its authority and limits. The root
`google_apps_script.js` is an older implementation, not the recovered snapshot.

Respect the user's request to conserve usage: search the inventory first, read only
relevant source ranges, keep tool output small, and reuse existing audit evidence
for unchanged code. Do not reload the full archive or rerun broad checks without a
specific need. Do not ask for the already-preserved export again.

Source recovery is not migration completion. Keep the original export unchanged;
track later exports separately. Active triggers/deployments and the external refresh
server remain unverified. Preserve the mockup-first scope and documented business
decisions; do not silently choose a different inventory or pricing authority.
