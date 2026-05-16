# Manual storefront checkout verification

Use this checklist to verify the end-to-end promotion flow on a real WooCommerce storefront (cart fee, order meta, redemption, audit, idempotency, and reversal). Run in a **staging or local** environment with a test payment method.

**WP-CLI quick checks (optional):**

```bash
./wp option get mp_cp_schema_version
./wp plugin status mp-commerce-promotions woocommerce
```

---

## 1. Preconditions

- [ ] **MP Commerce Promotions** plugin is **active**
- [ ] **WooCommerce** is **active**
- [ ] Schema version is **1.1.0** (`mp_cp_schema_version` option, or confirm after plugin activate)
- [ ] At least one **simple product** is **published** and purchasable
- [ ] A **test payment method** is available (e.g. Cash on delivery, BACS, or a gateway test mode)
- [ ] No unrelated promotion test rows are **active** (pause/archive other test promotions so only the checklist promotion can apply)

---

## 2. Create promotion

In **WooCommerce → Promotions**, create and configure a test promotion:

- [ ] **Create draft promotion**
- [ ] **Name:** `Manual Checkout Test 10 Percent`
- [ ] **Conditions (JSON):**

```json
[
  {"type":"minimum_subtotal","amount":1}
]
```

- [ ] **Actions (JSON):**

```json
[
  {"type":"percentage_discount","percentage":10}
]
```

- [ ] **Save** the promotion
- [ ] **Activate** the promotion (status **active**)

---

## 3. Cart test

On the **storefront** (not wp-admin), in a browser session with a cart:

- [ ] Add the simple product to the cart
- [ ] Open cart or checkout and confirm a **negative fee** appears with label similar to:  
      `Commerce promotion: Manual Checkout Test 10 Percent`
- [ ] Confirm the fee amount is **approximately 10% of cart subtotal** (before the fee; subtotal × 10 / 100, clamped to subtotal)
- [ ] Confirm **only one** commerce promotion fee appears (no duplicate promotion fees)

---

## 4. Checkout / order test

- [ ] Place a **test order** using the test payment method
- [ ] Confirm the order is **created** successfully
- [ ] In the order (admin or database), confirm order meta:

| Meta key | Expected |
|----------|----------|
| `_mp_cp_promotion_id` | Set (numeric promotion id) |
| `_mp_cp_promotion_uuid` | Set |
| `_mp_cp_promotion_name` | `Manual Checkout Test 10 Percent` (or sanitized equivalent) |
| `_mp_cp_discount_amount` | Set (matches applied discount) |
| `_mp_cp_action_type` | `percentage_discount` |
| `_mp_cp_percentage` | `10` (or formatted decimal) |
| `_mp_cp_redemption_recorded` | `yes` |

---

## 5. Database checks

Replace `wp_` with your table prefix if different.

- [ ] **`{prefix}mp_cp_redemptions`:** exactly **one** row for this **order_id** + **promotion_id** (status **recorded**)
- [ ] **`{prefix}mp_cp_promotions`:** **`usage_count`** for the test promotion incremented by **1** vs before checkout
- [ ] **`{prefix}mp_cp_audit_log`:** at least one row with action **`promotion.redeemed`** for this promotion (context includes **order_id**)

**Example WP-CLI (adjust ids):**

```bash
./wp db query "SELECT id, order_id, promotion_id, status FROM wp_mp_cp_redemptions WHERE order_id = ORDER_ID"
./wp db query "SELECT usage_count FROM wp_mp_cp_promotions WHERE name LIKE '%Manual Checkout Test%'"
./wp db query "SELECT action, context FROM wp_mp_cp_audit_log WHERE promotion_id = PROMO_ID ORDER BY id DESC LIMIT 5"
```

---

## 6. Idempotency check

Verify recording does not run twice for the same order/promotion:

- [ ] Note current **`usage_count`** and redemption row **id**
- [ ] If safe for your setup, **recalculate totals** or **save** the order again (or re-run the checkout hook path without creating a new order)
- [ ] Confirm **no second** redemption row for the same **order_id** + **promotion_id**
- [ ] Confirm **`usage_count`** did **not** increment again
- [ ] Confirm **no duplicate** **`promotion.redeemed`** audit for the same order (only one redeem audit for this checkout)

---

## 7. Reversal check

- [ ] **Cancel** the order, or mark it **fully refunded** (per your WooCommerce workflow)
- [ ] In **`{prefix}mp_cp_redemptions`**, confirm the row **status** is **`reversed`**
- [ ] Confirm promotion **`usage_count`** **decreased by 1** (and is **not below 0**)
- [ ] Confirm order meta **`_mp_cp_redemption_reversed`** = **`yes`**
- [ ] Confirm **`{prefix}mp_cp_audit_log`** has **one** **`promotion.redemption_reversed`** entry for this order/promotion
- [ ] If safe, **save** the order again or repeat cancel/status change
- [ ] Confirm **`usage_count`** did **not** decrement again
- [ ] Confirm **no duplicate** **`promotion.redemption_reversed`** audit for the same order

---

## 8. Cleanup

- [ ] **Archive** (or otherwise deactivate) the test promotion **`Manual Checkout Test 10 Percent`**
- [ ] Confirm **no active** test promotion remains that could affect storefront carts
- [ ] Leaving test **redemption** and **audit** rows in the database is **acceptable**

---

## 9. Known limitations

Do **not** expect the following in v1; failures here are out of scope for this checklist:

- **Negative cart fee strategy** — discount is a WooCommerce **fee** (negative amount), not a native coupon line
- **No partial refund logic** — only full **refunded** status (or cancel/failed/trash/delete paths) triggers reversal; partial refunds do not proportionally adjust usage
- **No coupon codes** — promotions are not WooCommerce coupons
- **No BOGO / free product** actions yet
- **Only the first eligible promotion** applies (by priority / active list order)
- **Admin “Preview against current cart”** may **not** match the storefront session cart (different context; preview does not add fees)

---

## Pass criteria

All checked items in sections **1–8** pass, and behavior matches **Known limitations** in section **9**.
