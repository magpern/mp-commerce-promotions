# Manual test: free shipping qualification

Free shipping **progress** (Biopentra cart) and Commerce Growth **`free_shipping`** promotions use **paid shippable merchandise subtotal** from `ShippingQualifiedSubtotalCalculator`, not WooCommerce cart subtotal before exclusions.

## Included

- Shippable products the customer pays for (line subtotal after line-level price changes, minus promotional exclusions below).

## Excluded

| Reason | Examples |
|--------|----------|
| `gift_card_product` | `_mp_cp_sells_gift_card=yes` lines |
| `non_shippable` | Virtual / `needs_shipping` false |
| `free_gift` | Cart lines with `mp_cp_free_gift=yes` |
| `free_promotional_unit` / `discount_allocation` | BOGO / `cheapest_item_discount` free or discounted units (from applied promotion session) |
| `fully_discounted_unit` | Zero-subtotal lines |

**Limitation:** Fee-based cart discounts (percentage/fixed **fees**) do not reduce qualifying subtotal until `mp_cp_line_allocations` is populated or cart line prices are mutated on `woocommerce_before_calculate_totals`.

## Quick checks

1. Gift card only → no progress bar; qualifying subtotal **0**; `free_shipping` with `minimum_subtotal` does not pass.
2. Physical €100 only → qualifying **100**; threshold 50 passes.
3. Gift €100 + physical €50 → qualifying **50**.
4. Free gift €10 + paid physical €100 → qualifying **100** (gift excluded).
5. Buy 3 get 1 free, €10 × 4 → qualifying **30** (not 40).

## WP-CLI

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/shipping-qualified-subtotal-smoke.php
```

Browser: configure shipping zone with non-zero cost; active promotion `free_shipping` + `minimum_subtotal`; confirm fee offset only when qualifying subtotal meets threshold. See [manual-checkout-test.md](manual-checkout-test.md) §11.
