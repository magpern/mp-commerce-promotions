# Manual promotion code storefront verification

Use this checklist to verify **manual promotion code redemption** through the standard **WooCommerce coupon field** (virtual coupon + negative cart fee). Run in **staging or local** with a test payment method when placing a real order.

**Browser storefront verification is preferred.** WP-CLI can test the coupon bridge filters and order recording, but cart **subtotal** and **fee calculation** are often wrong in CLI — confirm fee label and amount on the **storefront cart**.

**Virtual coupon behavior:** matching codes return a **virtual** `WC_Coupon` payload with **0** native WooCommerce discount. The real discount is still a **negative cart fee** from this plugin. WooCommerce accepts the code in the coupon field; the fee line shows e.g. `Commerce promotion code: ****5555`.

**Reversal:** cancel/refund/trash/delete decrements **both** promotion and **code** `usage_count` by at most **1** each (idempotent; never below 0). Redemption row becomes **`reversed`**.

**Related:** [manual-checkout-test.md](manual-checkout-test.md) (automatic promotion fee flow without codes).

**WP-CLI quick checks (optional):**

```bash
./wp option get mp_cp_schema_version
./wp plugin is-active mp-commerce-promotions
./wp plugin is-active woocommerce
./wp option get mp_cp_cart_discounts_enabled
```

---

## 1. Preconditions

- [ ] **MP Commerce Promotions** plugin is **active**
- [ ] **WooCommerce** is **active**
- [ ] Schema version is **1.2.0** (`mp_cp_schema_version`)
- [ ] **Cart discounts** enabled (`mp_cp_cart_discounts_enabled` = `yes`, or filter allows discounts)
- [ ] At least one **published** product is purchasable (simple product with price &gt; 0 recommended)
- [ ] Pause/archive **other active test promotions** so only this checklist promotion can apply automatically

---

## 2. Create active test promotion

In **WooCommerce → Promotions**, create and configure:

- [ ] **Name:** `Manual Code Test 5 Off`
- [ ] **Conditions (JSON):**

```json
[
  {"type":"minimum_subtotal","amount":1}
]
```

- [ ] **Actions (JSON):**

```json
[
  {"type":"fixed_amount_discount","amount":5}
]
```

- [ ] **Save** the promotion
- [ ] **Activate** (status **active**)

---

## 3. Create manual promotion code

On the promotion **edit** screen, **Promotion Codes** section:

- [ ] **Code:** `MANUAL-CODE-5555`
- [ ] **Create promotion code**
- [ ] After save, confirm **full code is not shown** in the list (only **Last 4**, e.g. `****5555`)
- [ ] Confirm row **status** = `active`
- [ ] Confirm **usage_count** = `0`

**Note:** `MANUAL-CODE-5555` is **globally unique** in the database (`code_hash`). Remove or disable any prior test row before reusing the same string.

---

## 4. Storefront coupon field test

On the **storefront** (browser, not wp-admin):

- [ ] Add a product to the cart (subtotal &gt; 0)
- [ ] Enter **`MANUAL-CODE-5555`** in the WooCommerce **coupon** field and apply
- [ ] Confirm WooCommerce **accepts** the coupon (no “coupon does not exist” error)
- [ ] Confirm cart shows a **negative fee** labeled similar to:  
      `Commerce promotion code: ****5555`
- [ ] Confirm fee amount = **`min(5, cart subtotal)`** (e.g. €5 off when subtotal ≥ €5)
- [ ] Confirm **no second** commerce promotion fee from **automatic** promotion selection (only the code-driven fee)

---

## 5. Invalid / unusable code checks

- [ ] Apply a **random** coupon code (not in `mp_cp_promotion_codes`) — confirm it does **not** apply our promotion fee
- [ ] **Disable** the test code (or set `expires_at` in the past if you add that workflow later) — confirm coupon is **rejected** or fee does **not** apply
- [ ] With only an **unusable** MP code on the cart, confirm **automatic** promotions do **not** run either

---

## 6. Checkout / order test

- [ ] Place a **test order** (test gateway / COD / BACS)
- [ ] Confirm order meta:

| Meta key | Expected |
|----------|----------|
| `_mp_cp_promotion_code_id` | Numeric code row id |
| `_mp_cp_promotion_code_last4` | `5555` |
| `_mp_cp_promotion_id` | Test promotion id |
| `_mp_cp_discount_amount` | Applied discount |
| `_mp_cp_action_type` | `fixed_amount_discount` |
| `_mp_cp_fixed_amount` | `5` (or formatted decimal) |
| `_mp_cp_redemption_recorded` | `yes` |

---

## 7. Usage checks

- [ ] `{prefix}mp_cp_promotions.usage_count` for test promotion incremented by **1**
- [ ] `{prefix}mp_cp_promotion_codes.usage_count` for test code incremented by **1**
- [ ] One **`recorded`** row in `{prefix}mp_cp_redemptions` for `(order_id, promotion_id)`
- [ ] Audit row **`promotion.redeemed`** exists for the promotion
- [ ] Triggering checkout recording again for the same order does **not** increment promotion or code usage again (idempotent)

**WP-CLI examples:**

```bash
./wp db query "SELECT usage_count FROM wp_mp_cp_promotions WHERE name='Manual Code Test 5 Off' ORDER BY id DESC LIMIT 1"
./wp db query "SELECT usage_count FROM wp_mp_cp_promotion_codes WHERE code_last4='5555' ORDER BY id DESC LIMIT 1"
./wp db query "SELECT * FROM wp_mp_cp_redemptions WHERE promotion_id=<ID> ORDER BY id DESC LIMIT 3"
```

---

## 8. Reversal checks

- [ ] **Cancel** or **refund** the test order (full order, not partial refund)
- [ ] Confirm promotion **`usage_count`** decrements by **1** (not below 0)
- [ ] Confirm **code `usage_count`** decrements by **1** (not below 0)
- [ ] Confirm redemption **`status`** = `reversed`
- [ ] Run reversal again on the same order — confirm **neither** promotion nor code **`usage_count`** changes again
- [ ] Confirm only **one** `promotion.redemption_reversed` audit entry for that order/promotion

---

## 9. Cleanup

- [ ] **Archive** test promotion `Manual Code Test 5 Off`
- [ ] Confirm **no** promotion with that name remains **active**
- [ ] Leaving **audit**, **redemption**, and **code** rows in place is acceptable (no hard delete API)

---

## 10. Known limitations

- **Virtual coupon, real fee** — WooCommerce coupon validation passes with **amount 0**; discount appears only as a **negative cart fee** (same strategy as automatic promotions).
- **WP-CLI cart subtotal unreliable** — do not treat CLI `get_subtotal()` as proof; verify fees in a **browser** (section 4).
- Only the **first** matching applied coupon that resolves to an MP promotion code is used; automatic promotions are skipped when a usable MP code coupon is on the cart.
- **`code_hash`** is globally unique — the same code string cannot be assigned to two promotions.
- **Reversal works** — promotion and code **`usage_count`** each decrement at most once; **`promotion.redemption_reversed`** audit is idempotent.
- **No partial refunds** — full cancel/refund/trash/delete paths only.
- **No** generated code batches UI on storefront, PDF/email, partner logic, or custom code entry form (coupon field only).

---

## Test run — 2026-05-16

Environment: local Docker WooCommerce (`./wp`), plugin commit **572ab80** (live `wp-content/plugins/mp-commerce-promotions`).

### Preconditions

| Check | Result |
|-------|--------|
| Schema `1.2.0` | Pass |
| Cart discounts `yes` | Pass |
| Plugins active (CLI `is_plugin_active`) | Inconclusive in eval context; plugins confirmed active via `./wp plugin is-active` separately |
| Product with price | Pass — product **3705** (Kisspeptin, €49) |

### Setup (WP-CLI)

| Step | Result |
|------|--------|
| Created promotion **Manual Code Test 5 Off** (id **25**, status **active**) | Pass |
| Created code **MANUAL-CODE-5555** (id **5**, last4 **5555**, usage **0**) | Pass |
| `is_code_usable` | Pass |
| Removed stale code row on archived promotion **22** before insert | Test setup only (`DELETE` via repository lookup) |

### Coupon bridge (WP-CLI)

| Check | Result |
|-------|--------|
| `woocommerce_get_shop_coupon_data` virtual payload for `MANUAL-CODE-5555` | Pass |
| Random code `NOT-REAL-CODE-XYZ` — no virtual coupon | Pass |
| `apply_coupon('MANUAL-CODE-5555')` | Pass |

### Storefront cart fee (browser)

| Check | Result |
|-------|--------|
| Fee `Commerce promotion code: ****5555` | **Not run** — WP-CLI cart `subtotal=0`; fees not calculated |
| Session `mp_cp_applied_promotion` with `promotion_code_id` / `last4` | **Not run** in CLI (session null) |

**Blocker for automated cart fee:** WooCommerce cart in WP-CLI does not populate line subtotals; **manual browser verification required** for section 4.

### Order recording (WP-CLI, session seeded when CLI cart failed)

| Check | Result |
|-------|--------|
| Order **#4315** created | Pass |
| Meta `_mp_cp_promotion_code_id` = **5** | Pass |
| Meta `_mp_cp_promotion_code_last4` = **5555** | Pass |
| Meta `_mp_cp_promotion_id`, `_mp_cp_discount_amount`, `_mp_cp_action_type`, `_mp_cp_fixed_amount`, `_mp_cp_redemption_recorded` | Pass |
| Promotion `usage_count` +1 | Pass |
| Code `usage_count` +1 | Pass |
| Redemption row count = 1 | Pass |
| Audit `promotion.redeemed` = 1 | Pass |
| Duplicate `record_on_order_create` — no extra usage | Pass |

### Reversal (WP-CLI)

| Check | Result |
|-------|--------|
| Order cancelled → redemption **reversed** | Pass |
| Promotion `usage_count` back to **0** | Pass |
| Code `usage_count` decremented to **0** | Pass (commit **9dea8c6**; previously stayed at 1) |
| Second `reverse_for_order` — no further decrement | Pass |
| Single `promotion.redemption_reversed` audit row | Pass |

### Unusable code (WP-CLI)

| Check | Result |
|-------|--------|
| Code status set **disabled** → `is_code_usable` false | Pass |

### Cleanup

| Step | Result |
|------|--------|
| Promotion **25** archived | Pass |
| Stray active duplicate promotion **24** (failed earlier run) archived manually | Pass |
| Code row **5** left **disabled**, usage_count **1** | Acceptable |

### Summary

- **Programmatic coverage:** coupon bridge, order meta, usage idempotency, reversal (promotion only), disabled code — **pass**.
- **Requires browser:** cart fee label/amount and WooCommerce coupon UX (section 4).
- **No plugin bugs found** during this run; no code changes made.
