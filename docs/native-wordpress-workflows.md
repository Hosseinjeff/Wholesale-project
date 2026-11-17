# Native WordPress workflows

The owner's latest instruction keeps bot-related processing on Railway and moves
all other workflows to WordPress on both Staging and production. This supersedes
the earlier assessment of moving the bot itself. No separate dashboard or second
business ledger is introduced.

The independent runtime MU-plugin files contain native PHP domain functions and a
gateway for the existing WordPress dashboard requests. Each completed method/path
is activated explicitly through the protected workflow configuration endpoint.
Unported methods keep their existing execution path. An activated method fails
locally if its dependencies fail; it never silently retries a write on Railway.
Native writes require active WordPress state custody and an existing explicit
WordPress business-owner identity. Config/status/shadow requests use the existing
runtime HMAC, remain private and never return connector credentials.

Implemented in source as of 2026-11-17:

- Existing SQLite schemas and local transactions, shared cross-runtime leases,
  compatible JSON revision hashes and immutable queue identities.
- Native Google Sheets OAuth/REST connector with private configuration outside
  the web root. Configuration is not yet transferred or activated.
- Inventory receipt/replay, correction, adjustment, hold/release, reservations and
  clearance calculations with the existing audit and revision rules.
- Paid-order allocation and procurement proposals, including protected payment
  state, fixed-pack rounding, prior-source checks and idempotent terms decisions.
  Order workflow actions and dashboard integration remain to be ported.
- Native operations queue pagination/worker status and reference snapshot/row and
  exception-list reads. Inventory/clearance and sender-enrollment HTTP contracts.

`tests/native_domain_parity.php` compares native results against synthetic golden
fixtures produced by the existing Python classes: 51 checks pass. Cases include
Unicode receipts/hashes, reservation replay, stock conflicts, paid-order source
changes, cancellation, supplier pack rounding, stable queue pagination, reference
preview/coverage, and gateway owner/method/body/cursor guards. Fixtures contain no
business data and call no AI or external APIs.

Deployment and activation are separate. Record actual per-site receipts below;
source presence does not mean a route has switched. Production's old inactive
state candidates must never be used without fresh transfer verification.

Remaining migration domains: reference fetch/incremental activation and matching;
catalog/Sheet reconciliation and guarded publication; current product and AI review
read models/commands; supplier freshness and publication recovery; order workflow
actions/substitutions and native order Sheet writes; native non-bot job scheduling
and bot-only Railway queue claims. Keep the existing policies and receipts when
porting these domains. Do not claim the whole migration is complete yet.

The source incorporates `466c71d`/`7b87352`, preserving Staging's deployed text-only
bot and free Google routing work. Its Railway source pin must not be rolled back to
the earlier checkpoint in `wordpress-state-executor.md`.
