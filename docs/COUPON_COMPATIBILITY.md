# Coupon compatibility

Native WooCommerce coupons coexist with MP Commerce Promotions via `coupon_behavior` on each promotion and `CouponCoexistenceEvaluator` at planner time.

## Matrix scenarios

| ID | Scenario | Risk |
|----|----------|------|
| `native_only` | Woo coupons only | Low |
| `mp_cp_only` | MP CP only | Low |
| `mixed_fee_native` | Fee + native | Medium |
| `mixed_line_native` | Line + native | High |
| `shipping_coupons` | Shipping coupon + free shipping promo | Medium |
| `auto_coupons` | Multiple auto coupons | Medium |

## Telemetry (Diagnostics)

Rolling counters in `PromotionPerformanceProfiler`:

- `blocked_by_coupon_count` — planner skips (`blocked_by_coupon`)
- `coexistence_fallback_count` — line→fee fallbacks
- `coupon_conflict_count` — line mode + native coupon conflicts

## Smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/coupon-compatibility-smoke.php
```

## Certification

Record results in **Diagnostics → Checkout certification** (`mp_cp_certification_runs`, type `coupon_coexistence`).
