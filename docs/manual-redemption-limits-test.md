# Manual test: redemption limits and cart quantity

Verify promotion-level restrictions and cart quantity conditions on staging/local.

## Global usage limit

- [ ] Set **Global usage limit** on a promotion (e.g. `5`) and ensure **usage count** on the row reflects recorded redemptions (or set count manually in DB for testing).
- [ ] When `usage_count >= usage_limit`, promotion is **ineligible** in cart preview and storefront.
- [ ] Plan table / traces show `usage_limit_reached`.

## Per-customer usage limit

- [ ] Set **Per-customer usage limit** (e.g. `1`).
- [ ] Logged-in customer with prior recorded redemption for this promotion is **ineligible** (`customer_usage_limit_reached`).
- [ ] Guest checkout/cart shows **ineligible** with `customer_required_for_usage_tracking` when limit is set.

## Date window

- [ ] `starts_at` in the future → `promotion_not_started` (evaluator + plan).
- [ ] `ends_at` in the past → `promotion_expired`.
- [ ] Use site timezone (`current_time`) — same as `find_active()` SQL window.

## Cart quantity conditions

```json
[{"type":"minimum_cart_quantity","quantity":3}]
```

```json
[{"type":"maximum_cart_quantity","quantity":10}]
```

- [ ] Total units = sum of line `quantity` across cart rows.
- [ ] Below minimum or above maximum fails with `quantity_not_met` on condition trace.

## Simple Rule Builder

- [ ] **Minimum cart quantity** / **Maximum cart quantity** options with **Cart quantity** field.

## WP-CLI smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/redemption-limits-smoke.php
```

## Limitations

- Per-customer limits require a logged-in `customer_id` (no guest fingerprinting).
- `find_active()` still filters by date in SQL; evaluator enforces dates for all evaluation paths.
- Schema **1.7.0** adds `customer_usage_limit` via additive `dbDelta`.
