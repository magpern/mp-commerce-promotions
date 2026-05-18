# Compatibility matrix (ecosystem certification)

**Plugin:** MP Commerce Promotions `0.2.0-beta.1`  
**Schema:** 1.17.0  
**Phase:** Operational certification tooling + detection (no deep third-party integration).

## Certified (project QA)

| Area | Status | Notes |
|------|--------|-------|
| Classic checkout | Certified | Fee-based discounts, recording, reversal |
| HPOS | Certified | `custom_order_tables` declared |
| Cart/Checkout Blocks | Certified | `cart_checkout_blocks` declared; Store API recording |
| Planner / orchestration | Certified | Exclusive, stackable, exclusions, groups |
| Line discount (fee fallback) | Partial | Line UI display partial; fee/hybrid paths preferred |
| Native coupon coexistence | Operational | Matrix + telemetry + smoke; merchant must record certification runs |

## Coupon coexistence scenarios (Diagnostics matrix)

| Scenario | Risk | Guidance |
|----------|------|----------|
| Native coupons only | Low | Baseline WooCommerce behavior |
| MP CP only | Low | No native coupons on cart |
| Mixed fee + native coupons | Medium | Prefer `allow` or `block` explicitly on promotions |
| Mixed line discounts + native coupons | High | Diagnostics warns on stacked discounts |
| Shipping coupons | Medium | Verify shipping tax mode |
| Auto coupons | Medium | Watch recalculation loops |

See [COUPON_COMPATIBILITY.md](COUPON_COMPATIBILITY.md).

## Ecosystem detections (Diagnostics)

| Integration | Detection | Confidence | Notes |
|-------------|-----------|------------|-------|
| WooCommerce Subscriptions | `WC_Subscriptions` | Medium | Partial |
| Product Bundles | `WC_Bundles` / `WC_PB_VERSION` | Medium | Partial |
| Composite Products | `WC_Composite_Products` | Medium | Partial |
| Multi-currency | WCML, WOOCS, Aelia, WCPBC | high → unsupported | Line mode warns when unsupported |
| Tax-inclusive store | `wc_prices_include_tax()` | High | Line mode + scoped fixed warnings |
| Germanized / EU VAT | Germanized classes | Medium | Partial |
| Dynamic pricing | Discount rules plugins | Low | Partial |
| Memberships | `WC_Memberships` | Medium | Partial |
| Object cache / Redis | `wp_using_ext_object_cache()` | High | Partial |
| HPOS | `OrderUtil::custom_orders_table_usage_is_enabled()` | High | Certified when on |
| Cart/Checkout Blocks | Blocks package + declaration | High | Certified when declared |

## Certification tracking

Manual checkout certification runs are stored in `mp_cp_certification_runs`:

- `classic_checkout`
- `blocks_checkout`
- `line_mode`
- `coupon_coexistence`

Diagnostics shows latest status and stale warnings (>30 days).

## Smoke scripts

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/coupon-compatibility-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stress-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-compatibility-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/load-harness.php
```

See also [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md), [TAX_COMPATIBILITY.md](TAX_COMPATIBILITY.md).
