# Owner workbench redesign — prototype and implementation notes

This is an interactive, non-mutating prototype for review. Its sample counts,
products, channels, and dates are synthetic. It is not connected to WordPress,
Railway, Sheets, WooCommerce, or the extraction service.

## Workbench structure

1. **Inbox and extraction** — new source messages, extraction failures, OCR review,
   and owner approval.
2. **Product identity** — product matching and reference assignment.
3. **Price and evidence** — supplier price freshness and source checks.
4. **Publication and sync** — holds, sheet conflicts, and catalog status.
5. **Store operations** — inventory, clearance, and paid orders.

The main work area is task-oriented: each card has one stage, one next step, and one
primary action. Related history and secondary statuses stay behind a details control.
The same product can appear in more than one stage when it has separate work to do.

## Implemented re-extraction taxonomy

- **All eligible messages** covers ordinary text-bearing source messages that can be
  safely reprocessed. Messages already staged, dismissed, reprocessed under the current
  policy, or protected by an owner decision stay out of this selection. Image-only OCR
  keeps its separate review path.
- **Waiting for owner review** selects extraction messages in pending review statuses.
- **Linked to an existing catalog product** matches a source offer to a `Product ID`
  that is present in the current `Products` table. This confirms a catalog association;
  it does not claim that the WooCommerce item is currently published.
- Source channels can be selected together. The optional date range is inclusive and
  uses the source-message timestamp in Tehran time. Scope, channels, and dates combine
  with AND logic; grouping the preview by source channel or day changes its display only.
- The preview count and group counts come from the service. Starting a run recomputes
  that selection under the queue lock and rejects a stale count or digest.
- Runs use the currently configured AI extraction model and existing quality checks.
  There is no model selector and no new extraction policy in this redesign. Recent-policy
  replay and image OCR remain explicit work types because their eligibility and safeguards
  differ.

## Design principles

- Organize by the next owner decision, not by backend endpoint or card type.
- Show status in words as well as color; use one consistent primary action per card.
- Keep re-extraction separate from approving extracted products.
- Require a current preview and confirmation before the real API starts a batch.
- Preserve existing extraction policy and owner-decision rules.

## Files

- `index.html` — responsive, RTL prototype.
- `prototype.css` — visual system and responsive layout.
- `prototype.js` — stage navigation and synthetic filter behavior.

The production implementation is in `shop-wp/molavi-shop/includes/owner-workflows.js`,
`owner-workflows.css`, `operations-dashboard.php`, `app.py`, and
`catalog/ai_confirmations.py`. The prototype remains a non-connected visual reference.
