# Manual test: simulation and forecasting

Commerce intelligence milestone (schema **1.13.0**). Heuristic only — no ML, no AI, no REST/AJAX admin SPA, no WP-Cron automation for forecasts.

## Prerequisites

- Plugin active; `mp_cp_schema_version` = `1.13.0` (deactivate/reactivate if needed).
- At least one active promotion with actions.
- Optional: redemption and planner telemetry rows for richer forecasts.

## Simulation

1. **Reports → Promotion simulation** — confirm whole-cart quick run shows estimated discount.
2. Save a preset scenario (name + preset); confirm it appears in latest 20 list.
3. WP-CLI: `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/simulation-forecasting-smoke.php`

Presets include: whole cart, scoped products, category cart, high quantity, VIP, guest, cooldown-active customer.

## Forecasting

1. **Reports → Forecasting** — exposure and projected redemption volume (may be zero without history).
2. Forecast cache option `mp_cp_forecast_cache` refreshes on engine run (1h TTL).

## Replay (read-only)

Smoke and code path `PromotionReplayEngine::replay_catalog()` — compares historical context to current planner rules. Does not mutate promotions or redemptions.

## Overlap simulation

Conflict analyzer **simulate overlap** mode via `PromotionOverlapSimulator` — schedule + orchestration + stackable collisions. See Diagnostics/Reports overlap-related recommendations.

## Campaign bulk workflow

1. **All Promotions** — select rows, use **Campaign bulk updates** panel (schedule, orchestration, label, budget, cooldown).
2. Confirm audit `promotion.bulk_updated` in audit log when changes apply.

## Diagnostics recovery

**Simulation & forecasting recovery** — dry-run default; apply checkbox for telemetry reset. Tools: reset telemetry, reset forecast cache, recalc planner counters, validate scenarios, repair malformed rows (soft-archive).

## Planner performance

Reports → **Planner performance** — request and persisted simulated/cache hit/miss counters (`mp_cp_planner_performance_counters`).

## Snapshots

Edit promotion → **Recent snapshots** — intelligence metadata column; **Simulate (read-only)** on a snapshot row.

## Limitations

- No SaaS sync, LLM, or cron-driven forecast jobs.
- Simulations use synthetic carts unless scenario JSON specifies products/metadata.
- Replay estimates “what would happen today,” not historical re-execution of checkout.
