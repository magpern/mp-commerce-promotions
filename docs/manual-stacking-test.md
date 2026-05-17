# Manual stacking verification

Use this checklist to verify **stackable** promotion fees, **subtotal cap**, **exclusive** behavior, **exclusions**, **max applications**, and **code-linked** non-stacking. Run in **staging or local** with WooCommerce active.

**Related:** [manual-checkout-test.md](manual-checkout-test.md) (checkout/redemption), [manual-promotion-code-test.md](manual-promotion-code-test.md) (codes).

**WP-CLI smoke (optional):**

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/stacking-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/stacking-limits-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-shipping-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/cheapest-item-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-gift-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/checkout-integrity-smoke.php
```

**Cheapest item discount:** stackable promotions can combine a `cheapest_item_discount` fee with other discount fees (subtotal cap applies). See [manual-cheapest-item-test.md](manual-cheapest-item-test.md).

**Free gift product:** `free_gift_product` adds a cart line at zero price and does not consume the discount-fee subtotal cap. See [manual-free-gift-test.md](manual-free-gift-test.md).

**Usage limits:** global `usage_limit` and per-customer `customer_usage_limit` are enforced in the evaluator before conditions. See [manual-redemption-limits-test.md](manual-redemption-limits-test.md).

**Checkout integrity:** duplicate checkout hooks and repeated reversals are guarded; see [manual-checkout-integrity-test.md](manual-checkout-integrity-test.md).

---

## Current behavior (summary)

| Rule | Effect |
|------|--------|
| **Exclusive** (default) | One selected promotion in the plan; later promotions skipped (`blocked_by_exclusive_promotion` or `stopped_processing`). |
| **Stackable** + stop processing off | Multiple promotions may be selected; **one cart fee per selected promotion**. |
| **One action per promotion** | Only the first supported `percentage_discount` or `fixed_amount_discount` applies per promotion. |
| **Subtotal cap** | Sum of promotion discounts ≤ cart subtotal. |
| **Exclusions** | When promotion A is selected, listed IDs are skipped if evaluated **after** A (priority/order matters). |
| **Max applications** | Plan cap = minimum `max_applications` among selected promotions; not per-customer usage. |
| **Promotion code** | Only the linked promotion; automatic stackable promotions do **not** apply. |

---

## 1. Two stackable fixed discounts

- [ ] Create promotion **A**: `stackable`, **stop processing** unchecked, `fixed_amount_discount` **10**, low `minimum_subtotal` (e.g. 1), priority **1**, **active**.
- [ ] Create promotion **B**: same but **15**, priority **2**, **active**.
- [ ] Cart subtotal ≥ combined discounts (e.g. **100**).
- [ ] Storefront cart shows **two** negative fees (or session `applied_promotions` count **2**).
- [ ] `total_discount_amount` ≈ **25**.

---

## 2. Subtotal cap (combined discounts exceed subtotal)

- [ ] Archive test promotions from section 1 if needed.
- [ ] Create **C**: fixed **80**, stackable, stop processing off, priority 1.
- [ ] Create **D**: fixed **50**, stackable, stop processing off, priority 2.
- [ ] Cart with subtotal **~46** (single unit of a ~46 product) or use smoke script output.
- [ ] **Expected:** `total_discount_amount` = **subtotal** (e.g. **46**), not **130**.
- [ ] Admin cart preview **Promotion plan** shows both selected; session/fees reflect capped amounts.

---

## 3. Exclusive promotion blocks later promos

- [ ] Create **E**: `exclusive`, stop processing on, eligible on current cart, priority 1.
- [ ] Ensure another eligible stackable promotion exists.
- [ ] Only **E** fee applies; second promotion skipped in plan (`blocked_by_exclusive_promotion` or `stopped_processing`).

---

## 4. Exclusion (A excludes B)

- [ ] **A** stackable, stop processing off, **Excluded promotion IDs** = B’s ID, priority 1.
- [ ] **B** stackable, eligible, priority 2.
- [ ] **C** stackable, eligible, priority 3 (optional).
- [ ] Plan selects **A** and **C**, skips **B** with `excluded_by_selected_promotion`.

---

## 5. Max applications = 2 (three eligible stackable)

- [ ] First promotion: `max_applications` **2**, stackable, stop processing off.
- [ ] Two more stackable promotions, no cap.
- [ ] Plan selects **two** promotions; third skipped with `max_applications_reached`.
- [ ] Cart session shows **2** `applied_promotions`.

---

## 6. Code-linked does not stack with automatic

- [ ] Active automatic stackable promotions on cart.
- [ ] Apply a valid **promotion code** for promotion **P**.
- [ ] Only **P** fee applies; automatic promotions not in session/plan for that cart.

---

## 7. Order recording and reversal (multi-promotion)

- [ ] Complete checkout with **two** stackable fees applied.
- [ ] Order has **two** redemption rows; `_mp_cp_applied_promotions` JSON array.
- [ ] Cancel order → both redemptions **reversed**, both `usage_count` decremented once.
- [ ] Re-run checkout hook on same order → **no** duplicate `usage_count` increment.

---

## Pass criteria

Sections **1–7** match expected behavior; admin **Promotion plan** table in cart preview aligns with storefront fees where the browser cart is available.

For **plan explanation** bullets and **conflict analysis** across active promotions, see [manual-conflict-analysis-test.md](manual-conflict-analysis-test.md).
