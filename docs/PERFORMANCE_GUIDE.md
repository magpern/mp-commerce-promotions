# Performance guide

Targets: 10k+ orders, hundreds of promotions, large carts.

## Planner

- Active promotions are loaded once per request via `PromotionRepository::find_active_for_planner()` (request memoization).
- Promotions without actions are prefiltered before evaluation.
- `PromotionPerformanceProfiler` records average/max runtime and **timing buckets** (`0-25ms` … `300ms+`).
- Diagnostics lists **high-complexity** active promotions (`PromotionComplexityScorer`).

## Caches

- `PlannerContextCache` / `AllocationContextCache` — request-level planner/allocation memoization.
- `PricingCompatibilityAnalyzer` / `EcosystemCompatibilityRegistry` — 30-minute option snapshots.

## Telemetry

- Disable or pause telemetry on hot paths if needed (`mp_cp_telemetry_paused`).
- Retention: `mp_cp_telemetry_retention_days` (default 90); run daily cleanup from Diagnostics.

## When planner is slow

1. Archive unused active promotions.
2. Reduce orchestration group congestion.
3. Avoid hundreds of simultaneous active promos; use scheduled windows.
4. Review Diagnostics → High-complexity active promotions.

## Storefront degraded mode

On planner exceptions, degraded mode skips automatic application. Clear from Diagnostics → Performance & hardening after fixing the root cause.
