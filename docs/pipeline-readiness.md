# Whole-pipeline readiness — 2026-10-29

**Current state: the public storefront is live at the user's direction. The root page
now opens the Shop storefront. Payment setup is out of scope for the current work. The
non-payment release gaps are the unset Tehran delivery area, owner-only access, connected
handoff verification, and remaining business review.**
The latest `Wholesale-project` deployment is healthy, with one running replica, the
500 MB `/data` volume, no staged Railway changes and no active service warnings. The
production `/health`, `/health/detailed` and `/api/shop` routes return HTTP 200. The
service-account reader returns the two current Products rows from Sheets. No customer
order, charge, refund or supplier purchase was made.

## Current live recheck — 2026-10-29

- `/` and `/shop/` return HTTP 200 and render the production storefront with two live
  products. The public Coming Soon screen is absent. The root previously displayed the
  default posts archive; WordPress now uses Shop page 7 as its static front page.
- The public Woo Store API returns two in-stock IRT products. The Shop page's Woo page
  assignments remain Cart 8, Checkout 9 and My Account 10.
- Woo's `molavi_tehran_delivery` method is enabled with the recorded 250,000 toman fee
  and 5,000,000 toman free-delivery threshold. Its Tehran zone has no locations, so the
  current zone configuration does not match customer addresses and cannot provide a
  shipping rate. Define the actual coverage before changing the zone.
- The authenticated environment login is administrator user 1 with Woo management
  capability. The project design reserves owner screens for user 3; the user 1 session
  receives WordPress's access-denied page. No owner access list was changed. Owner-only
  data and the live WordPress-to-Railway bridge therefore have not been rechecked in the
  authorized owner session.
- No order was placed and no payment, shipping-zone, gateway, or supplier-data setting
  was changed in this recheck.

## Telegram post check — 2026-10-29 22:18 UTC

- The primary `Wholesale-project` Telegram webhook received 27 `POST /webhook`
  requests around 22:14 UTC. All 27 returned HTTP 200 and the runtime log recorded
  each message as queued.
- A read-only check of the matching rows in Sheets found none of those message IDs
  in `MessageData`; there were also no matching `Offers` rows or product projection
  for those messages. No WooCommerce product update is confirmed for this batch.
- The durable handoff checks for an approved, usable reference snapshot before it
  calls the Sheets finalizer. The latest visible runtime record is a candidate
  staged for owner review. This is consistent with the batch waiting at the
  `waiting_reference` gate; the owner-only queue dashboard was not accessible to
  confirm the SQLite job state from this session.
- The webhook committed inbound receipts to the persistent pipeline queue before
  returning success. The posts remain recoverable there; they have not completed
  the Sheet extraction or Woo publication stages. Review the staged reference
  candidate through the existing owner screen, then recheck the same batch before
  treating these posts as processed.
- The message contents and chat identity are intentionally not copied into this
  project record.

## Fresh WordPress and live catalog

WordPress/WooCommerce runs on the user's fresh `h434558_Salesman` database. The
document root backup remains at `/home/h434558/public_html_recovery_20261029`; legacy
catalog, orders and media were not restored. The Shop page is WooCommerce page 7 and
now uses `[molavi_storefront]`, the production WooCommerce storefront based on the v2
visual and pricing direction. It is also the current static front page. WordPress
renders two live product cards. Responsive visual and business review remains pending.

Railway's Woo publish credential was rotated and matched on the private WordPress side.
The authenticated `/shop/publish` call succeeded, updating the two existing products.
Woo's public Store API now reports customer prices in toman (`IRT`): 73,320 and 71,850,
with regular market prices of 85,000 and 75,000. Supplier costs remain in private
product metadata; they no longer appear as the public Woo sale prices.

A previous disposable guest-cart check confirmed dynamic prices and the 250,000 / 5,000,000
toman fee calculation in isolation; those carts were cleared and no order was submitted.
The current live zone has no location rules, so that isolated result does not establish
that customers can receive a delivery rate on the public checkout.

## Whole-stream handoffs

| Handoff | Current evidence and status |
|---|---|
| Supplier → bot/server | Railway and its worker are online. The live Sheets-backed catalog returns two products. Row-level bilateral edits, conflict receipts and publication cadence still need connected verification. |
| Reference → extraction/review | The real Rezvan PDF → Gemini path staged 10 rows in snapshot `3adf331cfe5877a05b0637774f8dbdb1e02422a1da1cc7437880f62893edcca4`. It is not active and has written nothing to `ReferencePrices`. Owner approval remains required. |
| Offers → pricing/catalog | Woo now publishes the customer price band and keeps supplier cost private. Freshness gating and fixed-pack minimums are active. Clearance formula, near-cost alert behavior and connected recalculation still need review. |
| Catalog → WordPress/basket | Two products are synced in IRT; `/` and `/shop/` render the storefront. The delivery calculation exists, but the live Tehran zone has no matching locations. |
| Checkout → operator work | Woo page assignments are present. The environment login is not on the documented owner allowlist, so owner dashboards and their live bridge cannot be checked in this session. |
| Payment → fulfillment/follow-up | Paid-order lifecycle is synthetic-tested. No live payment callback or order was run; inventory/workflow ledger remains on Railway SQLite and is not mirrored to Sheets. |
| Daily reconciliation/recovery | The server worker and owner review surfaces exist. Bilateral Sheet edits and cutover of legacy/GAS writers remain unverified; legacy writers can bypass server locks. |

## Remaining implementation and review — excluding payment

1. Set the exact coverage locations for the already configured Tehran shipping zone.
   The fee and free-delivery threshold are recorded; the actual eligible addresses are
   not. The live zone currently has no location rules.
2. Inspect owner dashboards from the documented owner account (user 3). The available
   environment login is user 1 and is correctly denied by the project allowlist. Do not
   grant owner privileges to another account without explicit approval. Recheck the
   WordPress-to-Railway transport from the owner dashboard after authorized access.
3. Review the staged Rezvan/Gemini sample and activate it only after owner approval and
   a matching `ReferencePrices` write receipt. It is not active and has written nothing
   to that sheet.
4. Verify bilateral Products-sheet edits, conflict receipts and Woo publication in
   connected staging, then reconcile and cut over legacy/GAS writers after a guarded
   replay. This Railway project has no isolated staging environment; legacy writers can
   bypass server locks.
5. Finish the clearance formula, near-cost threshold, alert channel and approval rules;
   these require business settings and cannot be inferred from the code.
6. Validate persistent inventory/order-ledger reporting, backups and restore, operator
   recovery controls, browser/Elementor/Wordfence interactions, and the household then
   retailer end-to-end workflows. The v2 mockup's guest-request form is still only a
   demonstration; production uses WooCommerce checkout.

Payment configuration and checkout-payment validation are explicitly excluded from this
work and remain separate follow-up scope.

The local Products-sheet path covers normalized non-provenance fields, preserves
source evidence, blocks conflicted products and polls Sheet-only changes every 60
seconds. Synthetic coverage is not evidence of connected reconciliation. The earlier
source audits remain in `gas-migration-audit.md` and `recovered-gas-audit.md`; no broad
historical audit was repeated. Mockup v2 remains unapproved and no roleplay scene is
marked complete.

## Verification record

The latest disposable WordPress 7.1.3/WooCommerce 11.2.1 runtime run passed 102
assertions, including the new regression checks that customer-facing Woo prices use
the calculated band while supplier cost remains private and continues driving the
cart formula. The previous 246 offline Python tests remain the applicable server
baseline. Railway deployment `75b43fb6-8cd9-49f7-9c0a-4666ff3ff7cd` is successful;
the separate `FollowUP-bot` service was not changed.
