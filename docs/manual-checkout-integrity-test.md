# Manual test: checkout redemption integrity

## Scope

Verify idempotent redemption recording, reversal, free gift synchronization, and stacked promotions on a live WooCommerce store.

## Prerequisites

- Plugin active; WooCommerce cart/checkout available.
- At least one published product.
- WP-CLI smoke (evaluator/order helpers):

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/checkout-integrity-smoke.php
```

## Idempotency

- [ ] Place an order with an automatic or code promotion applied.
- [ ] Confirm `_mp_cp_redemption_recorded` = yes and redemption row exists.
- [ ] Re-run smoke or inspect DB: `usage_count` did not increase on duplicate `woocommerce_checkout_create_order` simulation (see smoke script).

## Stacked promotions

- [ ] Two stackable fixed-amount promotions on one cart.
- [ ] One order produces two redemption rows and `_mp_cp_applied_promotions` JSON with two entries.

## Reversal

- [ ] Cancel the order: redemption status → `reversed`, `_mp_cp_redemption_reversed` = yes, `usage_count` decremented once.
- [ ] Cancel again (or trash hooks): no further decrement.

## Restore (paid flow)

- [ ] After cancel, set order to **Processing** or **Completed**.
- [ ] Redemption returns to `recorded`, reversed meta cleared, `usage_count` incremented once.

## Free gifts

- [ ] Promotion with `free_gift_product` adds a line with `mp_cp_free_gift=yes` at zero price.
- [ ] Remove eligibility (e.g. lower subtotal): gift line removed on next cart totals.
- [ ] Manually change gift quantity in cart: next totals pass normalizes to configured quantity.
- [ ] Manually add the same product without `mp_cp_free_gift`: line is **not** removed.

## Admin

- [ ] Promotion edit → Cart preview shows free gift product ID, quantity, eligibility when action applies.
- [ ] Diagnostics → **Promotion integrity notes** section visible.

## Known limitations

- Gift lines on completed orders are not removed on reversal (by design).
- Full cart fee stacking requires browser checkout; smoke covers order meta and repository integrity.
- Block checkout not declared compatible.
