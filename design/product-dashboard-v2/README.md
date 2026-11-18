# Product dashboard rebuild

2026-11-15, Asia/Tehran. This replaces the stage board and product queue screens in the
WordPress Staging plugin. It implements the owner's request for a single product card
with context, next steps and collapsed related work.

## Navigation

Products is the default. Three scopes (needs attention, all, waiting), search, supplier
and action filters operate on the same deduplicated records. Old AI, reference-match,
price, conflict and hold URLs select this view and action; stable ID links select the
exact requested kind of ID. Products without a catalog ID remain visible by Offer or
case identity. Names never establish a join.

Every product uses `owner-dashboard.js`'s `productCard`. Its header has identity and
status; the next-action row explains what to do and opens the matching panel. The
same seven sections (source, details, reference, price, sync, publication, storefront)
stay available on the card. Only one action panel is open at a time. Panels mount
lazily and their form DOM is retained when switching panels or navigation, preserving
unfinished input. Refresh or a saved action invalidates those panels against new data.

Reference Catalog, Source Messages and System Status have stable navigation positions.
The reference screen presents the active local catalog, incremental refresh, the
initial activation when necessary and collapsed history. Source review opened from a
product retains its identity, original post and supplier, and preselects its channel
when the re-extraction preview recognizes it. System Status contains processing
receipts, recovery, account enrollment, saved-decision delivery and reversible task
hiding. Owned lot inventory, fulfillment and immutable status history remain explicit
links there because they operate on lots, orders and historical states.

## Data and actions

| Data | Purpose and use in this screen |
| --- | --- |
| Current Products/Offers status | Current read, stable identities and publication dependencies; no audit snapshot captured on the Products screen |
| Extraction cases | Editable proposed details, variants, source text/media and price/stock checks |
| Reference catalog | Saved Rezvan raw records and derived comparison fields, refreshed incrementally by stable source ID |
| Reference match cases | Product-specific reference and price-basis decisions |
| Supplier price evidence | Current price confirmation tied to its source revision |
| Sheet conflicts | Three-way values and revision-bound owner decisions |
| Publication holds / processing receipts | Shared batch recovery and processing outcome |
| Woo reconciliation | Explicit read of storefront visibility/purchasability; absent evidence stays unknown |
| WP review ledger / outbox | Pending decisions, validated responses and retryable delivery; viewing never drains it |
| User dismissals | Reversible personal visibility; never resolves or deletes business work |

`owner-workflows.js` now acts as the data/action adapter. It retains the audited review
routines, expected revisions, server response validation, WordPress decision records
and per-source refresh. The old independent product page renderers and queue layout
are removed. `reference-snapshots.js` exports a mountable catalog renderer shared with
the application. `owner-dashboard.css` defines one application theme; the remaining
workflow stylesheet contains the shared review/source controls.

Partial load failures keep the last read visible with a warning. Actions whose own
source failed to refresh are blocked until that source is current. A clean empty list
is never used to conceal a load failure. No business write happens on opening the app
or changing navigation.

## Verification

`node tests/owner_workbench.test.js` runs the rebuilt app in Chromium with synthetic
responses intercepted locally. It covers all four navigation sections, source-only
extraction cases, exact product-ID filtering, one expanded panel, desktop/phone
layout, no writes on reads, a failed refresh blocking a stale price action, and a
revision-bound supplier confirmation with pending/accepted audit receipts.
Screenshots are generated in `/tmp`, outside Git. This does not simulate a signed-in
live API decision. Production data and business records are outside this rebuild.
