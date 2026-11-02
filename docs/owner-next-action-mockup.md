# Owner workflow dashboard mockup

The dashboard starts with one ordered checklist. It shows live counts and opens the
matching review tab; it never approves, rejects, or republishes by itself.

## Desktop

```text
MolaviSale workflow

NEXT ACTION
Resolve 2 publication holds
These batches are paused until their results are reviewed.
[Review publication holds]

UP NEXT
2  Resolve sheet conflicts                 [Open]
3  Match supplier offers to catalog items  [Open]
4  Review extracted products               [Open]
   Failed background jobs: 178            [Technical details]

[Product extraction] [Reference] [Publication] [Inventory] [Orders] …
┌──────────────────────────────────────────────────────────────┐
│ Selected workflow screen                                     │
└──────────────────────────────────────────────────────────────┘

▸ Technical queue details
```

## Phone

```text
MolaviSale workflow

NEXT ACTION
Resolve 2 publication holds
These batches are paused until their results are reviewed.
[Review publication holds]

UP NEXT
2  Resolve sheet conflicts                 [Open]
3  Match supplier offers to catalog items  [Open]
4  Review extracted products               [Open]

▸ Technical queue details

[Scrollable workflow tabs]
```

The order is: publication holds, sheet conflicts, reference freshness, reference
matches, extraction decisions, supplier price checks, then technical job failures.
Zero-count queues stay out of the checklist. If a queue cannot be read, the screen
shows that status as unavailable instead of claiming there is no work. Queue counts
also appear on their tabs. Technical retry history and reconciliation remain available
in a collapsed details section.
