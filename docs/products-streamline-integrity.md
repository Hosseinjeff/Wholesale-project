# Product intake and shelf integrity

The product path is: authenticated Telegram update → durable Railway receipt →
append-only Offers row → current Products row → supplier-price freshness check →
complete WordPress/WooCommerce catalog projection. Google Sheets remains the
operational product book; WooCommerce is the public shelf. WordPress stores owner
review receipts and the catalog decisions needed by its dashboard. Railway keeps
the processing queue and freshness ledger.

Private Telegram updates are accepted only when the webhook carries Telegram's
configured secret header and the private chat is in `TELEGRAM_OPERATOR_CHAT_IDS`.
The Operators sheet is a display roster, not an access list or a production order
notification destination. Supplier channel posts retain the channel identity and
message URL. A channel edit uses Telegram's `edit_date` as the source update time,
and the edit timestamp participates in idempotency even if the text did not change.

Forwarded supplier posts keep the original supplier post date. Telegram does not
provide the supplier's edit timestamp in a forwarded message, so forwarding an old
post again cannot refresh its shelf life. The owner must verify the current source
when its original date is stale. A new supplier post or a direct channel update does
refresh the source timestamp.

Supplier price freshness warns after seven days and blocks new requests at fourteen
days. An explicit supplier out-of-stock statement immediately holds and hides that
offer. A recent offer with unknown availability stays visible for a customer request;
that request is not a promise of inventory. A later fresh offer can restore a held
product. The store card does not display a stock claim.

WordPress accepts a full catalog snapshot only after every row validates. It matches
products by stable Products-row ID or exact supplier/name identity; ambiguous or
cross-supplier title-only matches create a separate item rather than overwrite the
wrong product. Successful complete snapshots hide plugin-managed products removed
from the Products sheet. Held rows and products absent from a complete snapshot are
hidden and cannot be checked out from an older cart.

Completed batch payload text is compacted after ninety days, preserving message IDs,
source timestamps, URLs, lengths, and content hashes. The corresponding original
source remains in Sheets. Failed and review-needed jobs are retained intact. This
keeps Railway processing data bounded without deleting unresolved work.

The owner price-verification action requires a valid current source revision. When
the current row has no valid supplier price, the card explains the missing source
price and disables verification; the API rejects the empty revision with a conflict
response instead of a generic malformed-request error.

## Integrity checks and failure boundaries

Telegram webhook requests now require Telegram's secret header. Private messages
are accepted only from the owner's configured operator chat; groups are ignored.
Direct supplier-channel edits retain the source post URL and edit time, and that
time participates in receipt identity. Re-forwarding the same source post remains
idempotent and cannot make an old post look new. If an old post arrives after a
newer post, its append-only offer is retained for audit, while the current Products
row stays on the newer source revision.

Extraction no longer treats a missing stock statement as `Available`, and the
universal parser no longer interprets every mention of `تمام` or `❌` as a
product-level sold-out statement. A supplier-specific out-of-stock statement puts
the current offer on hold immediately. A recent offer with no stock statement stays
eligible for a request, but its stored state remains `Unknown`. An owner-entered
price changes price evidence only; it does not manufacture stock evidence.

The Railway receipt is committed before extraction. A failed extraction or Sheets
handoff remains in the review/retry queue and does not start publication. Offers are
append-only evidence; the Products row is the current editable book. Automatic
supplier updates win one-sided conflicts, while an intentional Sheet edit is sent
to WooCommerce. Simultaneous edits remain held until the owner chooses. WordPress
validates the whole catalog before writes, rejects duplicate product/source-row
identities, reports any failed upsert or hide, and only removes absent products
after every current row has been applied successfully. Matching uses stable row
identity or an unambiguous exact supplier/name pair, so a same-title item from a
different supplier cannot overwrite the wrong Woo product.

The existing age policy is a warning after seven days and a block after fourteen
days, measured from the source post timestamp rather than the time a post was
forwarded. Keep those intervals until a full supplier-cycle review shows that the
same-day/weekly cadence needs adjustment. The practical shelf rule is: hide on an
explicit supplier sold-out statement; otherwise allow a request for up to fourteen
days from a verifiable source update, warn the owner after seven days, then hide
until a newer source update or a documented manual verification. If a supplier
edits a channel post, Telegram's `edit_date` refreshes that evidence. A forwarded
post does not carry that edit timestamp, so an owner must check the original source
before manually refreshing an old post.

Successful completed payloads are compacted after ninety days; failed, pending and
review-needed receipts remain intact. Older failed work is deliberately not replayed
as a bulk operation: it needs a bounded reconciliation against its Sheets and Woo
receipts first, so retries cannot create duplicate offers or resurrect withdrawn
products.

## 2026-11-04 end-to-end audit

The owner’s Telegram private chat is the authorized operator. That authenticates who
submitted a message; it does not turn the owner’s send time into a supplier update
time. New intake records now distinguish a direct supplier-channel update, a forwarded
supplier-channel post, an owner submission, and a forward without supplier source time.
Only Telegram channel updates and channel-origin forwards create automatic supplier
freshness evidence. An owner-submitted post can still be reviewed and priced, but it
needs an explicit current-price verification before its send time can reopen the shelf.
That price decision does not set stock to available. Explicit supplier sold-out text
still holds the item; omitted stock stays Unknown.

A read-only live Sheets audit found 125 Products rows and 460 Offers rows. Ten current
Products rows pointed to a Latest Offer ID with no matching Offers row. Five of those
products were still purchasable in WooCommerce. The re-extraction IDs on most affected
rows and the prior writer's separate read-then-write row allocation make a concurrent
append overwriting an Offer row a credible failure mechanism; the live ledger alone
cannot prove that this caused every missing row. The current implementation uses
Google Sheets' atomic append and verifies each Offer ID exists exactly once before it
updates the current Products pointer. Catalog publication independently holds any
row whose Offer ID, immutable source fields, or source post URL no longer match its
Offer evidence. The owner workbench shows these cases on the product card and the
reconciliation report; it does not invent replacement Offer evidence.

One duplicate Product ID was repaired in the live Products sheet before this release
by replacing only the duplicate cell with the canonical stable ID. The follow-up audit
found all 125 Product IDs unique. The ten orphaned Offer pointers remain source-recovery
work; their product data is held from Woo until the source Offer is restored from
verifiable evidence.

The durable worker previously reclaimed `waiting_reference` and `waiting_publication`
jobs on timer backoff even when only an owner action could unblock them. The live queue
had a batch at 836 attempts and 63 receipts at 100 or more attempts. These wait states
now stay parked. A successful owner reference decision or snapshot activation wakes
reference waiters; clearing the final publication hold wakes publication waiters.
The worker also performs safe recovery on startup. This stops pointless retries while
preserving each receipt for audit.

The remaining source timestamp limitation is Telegram's lack of supplier edit time in
a forwarded message. Forwarding an old post again cannot refresh the supplier clock.
For automatic edit capture, the bot must receive the supplier channel's
`edited_channel_post`; otherwise the owner must verify the current price from the
original source. WordPress continues to persist each dashboard decision context,
decision, and server receipt in its owner-review table, while Railway retains the
durable execution queue and supplier freshness ledger.
