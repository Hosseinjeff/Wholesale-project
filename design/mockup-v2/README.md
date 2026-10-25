# MolaviSale mockup v2 — unapproved draft

This responsive, Persian storefront prototype uses illustrative products and prices.
It is versioned for design review and continuity. It is not connected to live orders,
payments or catalog APIs, and has not received design approval or completed browser
verification.

From the repository root, build a self-contained preview with Python:

```bash
python design/mockup-v2/build.py /tmp/molavi-mockup-v2
```

Open `/tmp/molavi-mockup-v2/review.html` to review desktop/phone views. The builder
uses existing product images, fonts and `catalog.pricing.price_band` from the repository.
Generated previews are build outputs; the six source files live in this directory.

The sample contact form does not save or send entered details. Use sample information.
See `docs/design-decisions.md` for pricing requirements, proposals and verification
limits; do not treat the prototype's proposed ordering flow as confirmed business policy.
