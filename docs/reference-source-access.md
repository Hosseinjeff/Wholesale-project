# ReferencePrices source recovery checkpoint

## Latest production refresh — 2026-10-31

The owner reported repeated refresh failures. Railway recorded the generic
`woocommerce_transport_failed` result, but the old importer did not preserve which
page or transport exception failed. Commit `a157ee5` now retries a complete fetch with
smaller pages and lower concurrency after transport, rate-limit or server errors, and
returns a safe page-specific error if retry still fails. It also improves the Persian
owner-page error message. Railway deployment `b7901971-078f-44f7-8ce5-46586d807e91`
and the WordPress JavaScript from that commit are live; the plugin uploader read the
file back successfully.

The owner-authorized retry succeeded: snapshot
`2976f2004a643e5546dc242ec876607dad1fd03d2fb6ee7cd124881d30497380` contains 3,919 of
3,919 products across 40 of 40 pages with verified coverage. It remains inactive and
`active_snapshot_id` is unset; no `ReferencePrices` write occurred. The full titles
`آبلیمو مجید کوچک180گ(138ف)20ع***` and `آبلیمو دست چین1/2ل (304ف)6ع***` were preserved;
their extracted values are respectively 180 grams / 20 per package and 0.5 liters / 6
per package. The candidate awaits owner review and explicit activation.

## Current source decision — 2026-10-30

The user has explicitly corrected the source: Rezvan Pakhsh reference products must be
fetched through its WooCommerce Store API, not extracted from the official PDF. The
production endpoint is
`https://rezvanpakhsh.com/wp-json/wc/store/v1/products`. A read-only audit fetched all
3,895 products across 39 pages; all had titles, SKUs, categories and positive IRR prices.
The importer converts IRR to toman and retains the full product title. The PDF/Gemini
code and snapshots described below are historical; old PDF candidates must stay inactive
and cannot be reviewed or activated.

Production confirmation — 2026-10-30: commit `3d8a7d2` deployed as Railway
`f87efd11-381c-4195-b82e-ce26263b78be`; Woo candidate
`4bf9d367c07dcf1af29e4fc61dfb1eddcb23cbbc4db3ffcdeb63fc987e80f7a9` staged 3,895/3,895
rows across 39/39 pages. Its coverage is verified, its active state is false, and no
`ReferencePrices` write occurred. The page's sample title/SKU/category/toman price was
checked against the live API. Owner review is the remaining reference decision.

Updated 2026-10-25, Asia/Tehran. The user supplied source as `gas.rar`; Google sign-in
is no longer needed to inspect those files. Do not ask the user to export them again.

- User-associated Apps Script ID:
  `1rGG6NvN06vtZ1yigBOrNrL6FnVbGdAKlNE4YMflNxrxf-1zDFkBze7-U`.
- Editor: `https://script.google.com/home/projects/1rGG6NvN06vtZ1yigBOrNrL6FnVbGdAKlNE4YMflNxrxf-1zDFkBze7-U/edit`.
- Earlier unauthenticated fetch returned no source. The TinyFish sign-in approach was
  offered, then the user chose another sharing method. No Google login was confirmed.
- Eight unchanged JavaScript files are locally preserved with SHA-256 hashes in
  `legacy-gas/recovered-2026-10-25/`; source read, 176 top-level declarations inventoried,
  and 18 offline behavior groups reproduced. The user subsequently authorized GitHub
  preservation for future chats. Use `legacy-gas/README.md`; no repeat upload is needed
  for chats with repository access. No GAS deployment was performed.
- Audit: `recovered-gas-audit.md`. The archive is user-associated with this project;
  authenticated linkage to the editor/deployed revision was not independently verified.

## Historical clarification — D-008 (superseded by D-036)

The earlier note identified Google inference and the recovered PDF/Gemini importer as
the Rezvan reference path. The user's later direct correction establishes the WooCommerce
Store API as the required source. The old PDF/Gemini implementation is not the production
reference source. The unrelated REFERENCE_REFRESH_SERVER_URL wrapper remains historical
code and is not required to fetch the public Store API.

## Remaining evidence (apply the clarification above)

1. Historical GAS main refresh reads `REFERENCE_REFRESH_SERVER_URL` from Script
   Properties and delegates to a separate server. The actual property value and server
   implementation are absent. This is not a prerequisite for the WooCommerce API
   source now selected by the user.
2. No `appsscript.json` or library/scope/timezone metadata is included.
3. No installed trigger list is included. Source defines daily reference refresh around
   04:00 in script timezone and a five-minute AI queue trigger. Do not assume installed.
4. No active deployment/binding list or Script Properties values are included. Check
   selected writer, mode, ownership and dependents before retiring or changing GAS.

Never execute refresh/setup/replay/mutation functions against the real project merely
to inspect it. Do not publish raw source, credentials or live supplier/customer data in
the shared handoff. The public snapshot contains sanitized audit documentation only.
