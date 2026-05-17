# Manual test: conflict analysis and planner explainability

Admin/debug workflow for understanding why promotions were selected or skipped and which configuration patterns may conflict. **No storefront behavior changes** in this milestone.

## Prerequisites

- Plugin active; WooCommerce cart available for cart preview.
- At least two **active** promotions with overlapping rules (subtotal conditions, stackable mode, etc.).
- WP-CLI from the WooCommerce Docker project: `./wp` (see project `CURSOR.md`).

## 1. Planner explanation on cart preview

1. Open **WooCommerce → Promotions → All Promotions** and edit an active promotion.
2. Add a product to the cart (storefront or admin session).
3. Click **Preview against current cart**.
4. Confirm sections appear in order:
   - Eligible / messages / evaluation traces (existing)
   - **Promotion plan** table (selected vs skipped + reason codes)
   - **Plan explanation** bullet list with human-readable lines, e.g.:
     - “Promotion 12 selected because eligible (stackable).”
     - “Promotion 15 skipped because excluded by promotion 12.”
     - “Promotion 18 skipped because max applications limit 2 was reached.”

**Pass:** Explanation bullets match the plan table; skipped exclusion and max-application reasons are readable.

## 2. Conflict analysis table

With multiple active promotions configured to overlap:

| Pattern | Expected conflict type (approx.) |
|--------|-----------------------------------|
| A excludes B and B excludes A | `mutual_exclusion` |
| One exclusive + several stackable | `exclusive_vs_stackable` |
| Two free shipping actions across promos | `free_shipping_overlap` |
| Two gifts for same product_id | `gift_overlap` |
| Scoped % discounts on same categories | `scope_overlap` (info/warning) |
| High-priority exclusive + low stackable | `priority_shadowing` |

1. Run cart preview on any active promotion.
2. Scroll to **Conflict analysis (active promotions)**.
3. Confirm columns: **Severity**, **Type**, **Promotions**, **Message** (escaped text only).

**Pass:** Relevant warnings appear when patterns exist; table may be empty when no heuristics fire.

**Limitation:** Heuristics do not simulate a real cart or guarantee checkout outcome.

## 3. All Promotions list indicators

1. Open **All Promotions**.
2. Check the **Application** column for lightweight tags (no per-row full analyzer):
   - Exclusive / Stackable
   - Has exclusions · Conflicts: N (N = count of `excluded_promotion_ids`)
   - Scoped (scoped actions or eligible subtotal conditions)
   - Stop (stop processing enabled)

**Pass:** Tags reflect promotion configuration without slow list loads.

## 4. Validation panel warnings

On edit screen, **Validation** panel should show **warnings/info** (not blocking errors) for:

- Exclusive promotion with `excluded_promotion_ids` (redundancy hint)
- `max_applications` likely unreachable with exclusive + stop processing
- Duplicate `free_gift_product` for same product in one promotion
- Multiple `free_shipping` actions in one promotion
- Multiple scoped discount actions or scoped overlap info

**Pass:** Warnings appear when configured; saving still allowed if no errors.

## 5. WP-CLI smoke (evaluator/planner only)

From WooCommerce project root:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/conflict-analysis-smoke.php
```

**Pass:** All checks report success; exit code 0.

## Operational debugging workflow

1. Reproduce cart in browser (or use preview subtotal context).
2. Run **cart preview** on the promotion under investigation.
3. Read **plan explanation** for selection/skip reasons (exclusions, caps, exclusive stop).
4. Read **conflict analysis** for cross-promotion configuration risks.
5. Adjust priority, exclusions, application mode, or scopes; re-preview.

## Known limitations

- Conflict detection is **static/heuristic** — it does not run `PromotionEvaluator` for every pair.
- List page **Conflicts: N** counts exclusion IDs only, not full analyzer results.
- No customer-facing messaging, REST, or AJAX in this milestone.
- Usage-limit conflicts warn when limits are set; they do not read live redemption counts during analysis.
