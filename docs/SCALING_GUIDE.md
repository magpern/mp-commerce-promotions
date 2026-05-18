# Scaling guide

## Database

- Promotions, redemptions, telemetry, and codes use custom tables (not post meta for core entities).
- Reports aggregate from redemptions; filter date ranges on large stores.
- Run Diagnostics budget/telemetry rebuild tools after bulk imports (dry-run first).

## Orders (10k+)

- Redemption rows are indexed by `order_id` and `promotion_id`.
- Checkout recording is idempotent per order/promotion.
- HPOS is supported; ensure order admin plugins are HPOS-aware.

## Many active promotions

- Planner cap: 200 active promotions per cart pass.
- Use **paused** / **archived** for campaigns not in market.
- Split stackable campaigns only when `stop_processing` is intentional.

## Large carts

- Line-item mode increases `before_calculate_totals` work — prefer fee-based on large carts.
- Free gift sync runs each totals pass; limit concurrent gift promos.

## Object cache

- External object cache is supported; run `PromotionConcurrencyGuard::purge_stale_locks()` after crashes.
- Compatibility snapshots cache for 30 minutes to reduce admin load.

## Automation at scale

- Cron automation is **off** by default.
- Use `mp_cp_automation_emergency_stop` to halt all automation quickly.
