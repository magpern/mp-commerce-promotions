# Compatibility matrix (GA stabilization)

**Plugin:** MP Commerce Promotions `0.2.0-beta.1`  
**Phase:** GA stabilization — detection only, no deep third-party integration.

## Certified (project QA)

| Area | Status | Notes |
|------|--------|-------|
| Classic checkout | Certified | Fee-based discounts, recording, reversal |
| HPOS | Certified | `custom_order_tables` declared |
| Cart/Checkout Blocks | Certified | `cart_checkout_blocks` declared; Store API recording |
| Planner / orchestration | Certified | Exclusive, stackable, exclusions, groups |
| Line discount (fee fallback) | Partial | Line UI display partial; fee/hybrid paths preferred |

## Ecosystem detections (Diagnostics)

| Integration | Detection | Typical status | Confidence when detected |
|-------------|-----------|----------------|--------------------------|
| WooCommerce Subscriptions | `WC_Subscriptions` | Partial | Medium |
| Product Bundles | `WC_Bundles` / `WC_PB_VERSION` | Partial | Medium |
| Composite Products | `WC_Composite_Products` | Partial | Medium |
| Multi-currency | WCML, WOOCS, Aelia, WCPBC | Partial | Medium |
| Tax-inclusive store | `wc_prices_include_tax()` | Partial | High |
| Germanized / EU VAT | Germanized classes | Partial | Medium |
| Dynamic pricing | Discount rules, WC Dynamic Pricing | Partial | Low |
| Memberships | `WC_Memberships` | Partial | Medium |
| Object cache / Redis | `wp_using_ext_object_cache()` | Partial | High |
| HPOS | `OrderUtil::custom_orders_table_usage_is_enabled()` | Certified when on | High |
| Cart/Checkout Blocks | Blocks package + declaration flag | Certified when declared | High |

Run live matrix:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stabilization-smoke.php
```

See also [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md).
