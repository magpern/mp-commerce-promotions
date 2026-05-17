# Browser QA matrix

Manual storefront and admin checks for MP Commerce Promotions. Use reproducible carts and promotion presets from Diagnostics or WP-CLI seed data.

## Environments

| Area | Check |
|------|--------|
| Classic checkout | Cart fees, coupons field, order recording |
| HPOS | Order meta and redemption rows after checkout |
| Taxes | Discount fees with tax settings enabled |
| Shipping | `free_shipping` action offsets shipping total |
| Coupons | Native coupon field with promotion codes |
| Free gift | Gift line added/removed on cart recalc |
| Stacking | Multiple stackable promotions, subtotal cap |
| Orchestration | Same orchestration group — one winner |
| Refunds | Reversal restores usage/budget |
| Guest checkout | Session applied promotions recorded |
| Logged-in checkout | Redemption count conditions |
| Mobile | Cart/checkout layout, notices |
| Admin | Settings safe mode, Diagnostics cleanup |

## Test promotion presets

| Preset | Expected outcome |
|--------|------------------|
| 10% subtotal automatic | Negative fee ≈ 10% of subtotal |
| Fixed $5 off | Fee −5 (capped at subtotal) |
| Code-only promotion | Applies only when coupon entered |
| Exclusive + stackable pair | Exclusive stops further selection |
| Budget exhausted | Skipped with budget reason in plan |
| Safe mode ON | No automatic fees; codes optional |

## Reproducible QA carts

| Cart | Lines | Subtotal target |
|------|-------|-----------------|
| Small | 1 × simple product | $25 |
| Medium | 3 × mixed categories | $120 |
| Large | 10+ lines | $500+ |

## Production safety

- Enable **Safe mode** → verify automatic promotions off.
- Enable **Telemetry pause** → no new telemetry increments.
- Enable **Automation emergency stop** → cron/manual automation blocked.
- Trigger planner failure (invalid test hook) → cart still loads; degraded notice optional.
