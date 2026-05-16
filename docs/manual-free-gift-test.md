# Manual test: free gift product

Use this checklist to verify **`free_gift_product`** on a staging or local WooCommerce storefront. This is the **first action that modifies cart contents** (adds a product line). Gift lines are priced at **0** via `woocommerce_before_calculate_totals`; **no negative cart fee** is added for the gift.

**Cart sync:** `FreeGiftCartSynchronizer` removes stale gifts when a promotion drops off, normalizes quantity on totals, and only touches lines with **`mp_cp_free_gift=yes`**. See [manual-checkout-integrity-test.md](manual-checkout-integrity-test.md).

## Prerequisites

- WooCommerce cart/checkout (classic checkout is fine; block checkout is **not** declared compatible).
- A **published simple product** to use as the gift (note its **product ID**).
- A **qualifying product** customers can buy to trigger the promotion (or reuse the gift product with a paid line + gift line).

## 1. Create gift product

- [ ] Publish a simple product (e.g. “Free sample”) with a non-zero regular price.
- [ ] Note **product ID** (Products list or admin helper on promotion edit).

## 2. Create promotion

Example actions JSON:

```json
[
  {
    "type": "free_gift_product",
    "product_id": 123,
    "quantity": 1
  }
]
```

Optional variation:

```json
[
  {
    "type": "free_gift_product",
    "product_id": 123,
    "variation_id": 456,
    "quantity": 1
  }
]
```

- [ ] Set conditions (e.g. `minimum_subtotal`) so the cart qualifies when a paid item is present.
- [ ] Activate the promotion (automatic or code-linked per your test).

Or use **Simple Rule Builder** → action **Free gift product** → gift product ID, optional variation ID, quantity.

## 3. Storefront cart

- [ ] Add a **qualifying paid** product to the cart (not only the gift).
- [ ] Open cart page — confirm the **gift product** appears as its own line.
- [ ] Confirm gift line **subtotal/price is 0** (or equivalent display).
- [ ] Refresh cart or change quantity on another item — confirm **duplicate gift lines are not added** for the same promotion + product/variation.

## 4. Checkout and order

- [ ] Complete checkout.
- [ ] Order includes the **gift line item** at zero (or near-zero) line total.
- [ ] Order meta **`_mp_cp_applied_promotions`** includes an entry with `action_type`: `free_gift_product`, `discount_amount`: `0`, and `product_id` / `quantity` (and `variation_id` if used).
- [ ] Redemptions table has a row for this promotion with **`discount_amount` = 0**.

## 5. Reversal

- [ ] Cancel, fail, refund, or trash the order (per your reversal test path).
- [ ] Confirm redemption is **reversed** and promotion **usage_count** decrements (idempotent).
- [ ] Confirm the **gift line remains on the existing order** (reversal does **not** remove physical line items from placed orders).

## 6. Admin preview

- [ ] **Edit promotion** → cart preview shows eligible result and action preview with `product_id`, `quantity`, optional `variation_id`.

## Limitations (MVP)

- Gift price is set on the cart product object at **0** before totals; not a native Woo “free product” coupon.
- **No** BOGO line splitting, **no** automatic gift selection, **no** variable-product picker UI (IDs only).
- **No** stock reservation for gifts.
- **Block checkout** compatibility is **not** declared.
- Reversal updates redemption/usage only; it does **not** remove gift lines from orders already created.

## WP-CLI smoke (optional)

From the WooCommerce project root:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-gift-smoke.php
```

Set `MP_CP_SMOKE_GIFT_PRODUCT_ID` if you need a specific product.
