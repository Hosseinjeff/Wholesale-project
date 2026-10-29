# Whole-pipeline readiness — 2026-10-29

**State: the Railway service and protected Woo catalog sync are working; the customer
release is still blocked by payment setup and the WordPress host's outbound network.**
The latest `Wholesale-project` deployment is healthy, with one running replica, the
500 MB `/data` volume, no staged Railway changes and no active service warnings. The
production `/health`, `/health/detailed` and `/api/shop` routes return HTTP 200. The
service-account reader returns the two current Products rows from Sheets. No customer
order, charge, refund or supplier purchase was made.

## Fresh WordPress and live catalog

WordPress/WooCommerce runs on the user's fresh `h434558_Salesman` database. The
document root backup remains at `/home/h434558/public_html_recovery_20261029`; legacy
catalog, orders and media were not restored. The Shop page is WooCommerce page 7 and
now uses `[molavi_storefront]`, the production WooCommerce storefront based on the v2
visual and pricing direction. WordPress rendered two live product cards. The visual and
business review remains pending, and the fresh Woo site is still in **Coming soon** mode
because no full-prepayment gateway is enabled. The static mockup remains unapproved.

Railway's Woo publish credential was rotated and matched on the private WordPress side.
The authenticated `/shop/publish` call succeeded, updating the two existing products.
Woo's public Store API now reports customer prices in toman (`IRT`): 73,320 and 71,850,
with regular market prices of 85,000 and 75,000. Supplier costs remain in private
product metadata; they no longer appear as the public Woo sale prices.

A disposable guest-cart check confirmed the dynamic price and Tehran delivery rules:
requesting one unit of the first product resolves to its six-unit minimum; the live
cart priced each unit at 72,847 toman, goods subtotal 437,082, plus the configured
250,000-toman delivery fee. A 100-unit test basket totalled 6,542,900 toman and got
free delivery. Both carts were cleared. No order was submitted. Woo checkout reports
no enabled payment methods, so the shop remains in Coming soon mode.

## Whole-stream handoffs

| Handoff | Current evidence and status |
|---|---|
| Supplier → bot/server | Railway and its worker are online. The live Sheets-backed catalog returns two products. Row-level bilateral edits, conflict receipts and publication cadence still need connected verification. |
| Reference → extraction/review | The real Rezvan PDF → Gemini path staged 10 rows in snapshot `3adf331cfe5877a05b0637774f8dbdb1e02422a1da1cc7437880f62893edcca4`. It is not active and has written nothing to `ReferencePrices`. Owner approval remains required. |
| Offers → pricing/catalog | Woo now publishes the customer price band and keeps supplier cost private. Freshness gating and fixed-pack minimums are active. Clearance formula, near-cost alert behavior and connected recalculation still need review. |
| Catalog → WordPress/basket | Two products are synced in IRT; dynamic cart price and Tehran fixed/free delivery were exercised without placing an order. Shop visibility remains Coming soon. |
| Checkout → operator work | Woo reports no enabled payment methods. The dashboard pages load, but their authenticated server bridge cannot reach Railway from the CloudyLink PHP host. |
| Payment → fulfillment/follow-up | Paid-order lifecycle is synthetic-tested. No live payment callback or order was run; inventory/workflow ledger remains on Railway SQLite and is not mirrored to Sheets. |
| Daily reconciliation/recovery | The server worker and owner review surfaces exist. Bilateral Sheet edits and cutover of legacy/GAS writers remain unverified; legacy writers can bypass server locks. |

## Current external and owner gates

1. The live WordPress PHP host's `wp_remote_get` to
   `https://wholesale-project-production.up.railway.app/health` returns
   `http_request_failed`. The same endpoint responds from the managed workspace. The
   owner reference dashboards therefore show a server-connection error. CloudyLink
   needs to allow outbound HTTPS from the WordPress host to the Railway service
   hostname (currently resolving to `69.46.46.40:443`), or provide a supported route.
   This is a host-network restriction, not evidence that the separate
   `REFERENCE_REFRESH_SERVER_URL` service exists or is required.
2. Select and configure the full-prepayment gateway and its credentials/account
   details. No Woo payment method is enabled, and the provider was not selected in the
   saved plan. Keep the shop in Coming soon mode until checkout can collect the full
   confirmed amount.
3. Restore the WordPress-to-Railway owner bridge, review the staged reference sample,
   and activate it only after owner approval and a matching `ReferencePrices` write
   receipt.
4. Verify live two-way Products-sheet reconciliation, conflict handling and Woo
   publication with direct Sheet edits; then cut over legacy/GAS writers after a
   guarded replay. This Railway project has no isolated staging environment.
5. Mirror inventory/workflow ledgers to an owner-visible governed store, finish the
   clearance and near-cost alert policy, and run the whole supplier-to-aftercare
   roleplay after checkout and the owner screens work end to end.

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
