# Manual test: advanced pricing engine (schema 1.14.0)

## Scope

Allocation, tax/shipping estimates, coupon coexistence, priority tiers, compatibility heuristics, and reporting — **admin/reporting only**. Storefront discounts still apply as WooCommerce cart fees by default. For **line_item** / **hybrid** modes see [manual-line-discount-engine-test.md](manual-line-discount-engine-test.md).

## Prerequisites

- Plugin active; schema `1.14.0` (`wp option get mp_cp_schema_version`).
- WooCommerce cart available for live coupon tests (optional).

## Fee-based vs allocated

1. Create a stackable promotion with 10% whole-cart discount.
2. Edit promotion → cart preview: confirm **allocation table** and effective savings rate.
3. Add products to cart on storefront: discount still appears as a **fee** (not per-line price changes).

## Priority tier

1. Set two active promotions: same tier `campaign`, different numeric priorities.
2. Set a third with tier `override` and lower numeric priority.
3. Cart preview / simulation: **override** promotion evaluated before **campaign** regardless of numeric priority.

## Coupon coexistence

| `coupon_behavior` | Expected |
|-------------------|----------|
| `coexist` | Promotion may apply with native coupons (warning in explainability). |
| `block_native` | Promotion skipped when a Woo coupon is applied. |
| `require_no_coupon` | Promotion skipped when no native coupon (diagnostic mode). |

## Tax and shipping heuristics

- Reports → pricing analytics: tax impact estimates are **heuristic** (respects Woo tax display mode when available).
- Free shipping + native shipping coupon: coexistence may warn or block per configuration.

## Diagnostics recovery

WooCommerce → Promotions → Diagnostics:

1. Rebuild allocation summaries (dry-run, then apply).
2. Normalize invalid priority tiers.

## Smoke

From WooCommerce project root:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pricing-engine-smoke.php
```

## Limitations

- No checkout tax/line mutation.
- Profitability metrics are estimates, not accounting.
- Multi-currency and bundle plugins: compatibility warnings only.
