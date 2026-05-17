# Manual cheapest item discount verification

Use this checklist to verify **cheapest_item_discount** (BOGO groundwork) on a staging or local WooCommerce storefront. The discount is applied as a **negative cart fee** — line item prices are **not** changed and **no free products** are added to the cart.

**Related docs:** [manual-scoped-discount-test.md](manual-scoped-discount-test.md) (`EligibleCartScope`), [manual-product-targeting-test.md](manual-product-targeting-test.md) (variation IDs, sale exclusion), [manual-checkout-test.md](manual-checkout-test.md), [manual-stacking-test.md](manual-stacking-test.md)

**WP-CLI smoke (evaluator only):**

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/cheapest-item-smoke.php
```

---

## 1. Preconditions

- [ ] **MP Commerce Promotions** and **WooCommerce** are active
- [ ] At least **three** simple products in the **same category** with different prices (e.g. 50, 30, 20)
- [ ] Products have known **product IDs** and **category term IDs** (use admin Product/category ID helper)
- [ ] Other test promotions are **paused/archived** so only your test promotion applies
- [ ] Cart discounts enabled (**WooCommerce → Promotions → Settings** or `mp_cp_enable_cart_discounts`)

---

## 2. Create promotion: Buy 2 get 1 free by category

Example JSON (category scope, 3 required, 1 discounted at 100%):

```json
[
  {
    "type": "cheapest_item_discount",
    "scope": "category",
    "category_ids": [10],
    "discount_percentage": 100,
    "required_quantity": 3,
    "discounted_quantity": 1
  }
]
```

Or use **Simple Rule Builder**:

- Action: **Cheapest item discount**
- Scope: **Category**
- Category IDs: comma-separated term IDs
- Required quantity: **3**
- Discounted quantity: **1**
- Discount percentage: **100**

- [ ] **Admin cart preview** shows calculated **discount_amount** (cheapest unit price) and **discounted_units: 1**
- [ ] Help text notes fee-offset MVP (line prices unchanged)

---

## 3. Cart with 3 matching products

Add **3 units** across products in the target category (e.g. one × 50, two × 30).

- [ ] Promotion is **eligible** in cart preview
- [ ] Storefront cart shows fee: **Commerce promotion: Cheapest item discount - {name}**
- [ ] Fee amount equals **cheapest unit price** (e.g. **30** when units are 50, 30, 30)
- [ ] Cart **line subtotals unchanged** (discount only in fees/total)

---

## 4. Insufficient quantity

Remove items until fewer than **required_quantity** eligible units remain.

- [ ] Cart preview shows **not_applicable** / **insufficient_eligible_quantity**
- [ ] **No** cheapest-item fee on storefront

---

## 5. 50% off cheapest item

Edit action: `discount_percentage: 50`, same scope/quantities.

- [ ] Preview **discount_amount** = half of cheapest unit price
- [ ] Storefront fee matches preview (subject to subtotal cap)

---

## 6. Product scope

```json
[
  {
    "type": "cheapest_item_discount",
    "scope": "products",
    "product_ids": [100, 101],
    "discount_percentage": 50,
    "required_quantity": 2,
    "discounted_quantity": 1
  }
]
```

- [ ] Only listed products count toward eligibility
- [ ] Discount applies to cheapest unit among those lines

---

## 7. Stacking and subtotal cap

**Exclusive (default):**

- [ ] Only one promotion fee when multiple promotions could apply

**Stackable:**

- [ ] Create two stackable promotions with cheapest_item or mixed actions
- [ ] Multiple fees when planner selects both
- [ ] **Total cart discount fees ≤ cart subtotal**

**Cap case:** configure discount that would exceed subtotal when combined with other fees — capped at remaining allowance.

---

## 8. Code-linked promotion

- [ ] Create promotion code linked to cheapest_item_discount promotion
- [ ] Apply code in coupon field
- [ ] Fee label: **Commerce promotion code: Cheapest item discount ****{last4}**
- [ ] Automatic promotions do **not** stack with code-linked path

---

## 9. Checkout recording and reversal

- [ ] Complete order with cheapest-item fee applied
- [ ] Redemption recorded; order meta includes applied promotion
- [ ] Cancel/refund order → redemption reversed; usage decremented idempotently

---

## Known limitations

| Limitation | Notes |
|------------|--------|
| Fee offset only | Does not change product line prices or add free product lines |
| No free products | “Buy 2 get 1 free” is modeled as discount on cheapest unit, not a 0-price line |
| Integer quantities | Fractional cart quantities use `floor` for unit expansion |
| No variation targeting | Product scope matches parent product ID only |
| One action per promotion | First supported action only on storefront |
| Block checkout | Compatibility not declared |

---

## Pass criteria

Sections **1–9** pass; behavior matches **Known limitations**; smoke script passes:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/cheapest-item-smoke.php
```
