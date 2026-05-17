# Manual test: performance and production hardening

## Reports dashboard

1. Open **WooCommerce → Promotions → Reports**.
2. **Production hardening** — verify safe mode, automatic promotions, degraded mode, telemetry/simulation pause, emergency stop, cron flags, retention days, compatibility confidence.
3. **Planner performance** — profiler aggregates, request/persisted cache counters, allocation hits/misses, slow planner runs table.

## Profiler

1. Add products to cart and recalculate totals several times.
2. Open **Reports → Planner performance** — confirm profiler averages and cache hit rate.
3. Open **Diagnostics → Performance & hardening** — confirm planner runs incremented.

## Safe mode and degraded mode

1. **Settings → Production safety** — enable Safe mode, save.
2. Cart should not apply automatic promotion fees; codes may still work if allowed.
3. Clear degraded state from Diagnostics if a test failure was recorded.

## WP-Cron

1. Enable **WP-Cron automation** in Settings (optional).
2. Diagnostics should show hourly/daily hooks scheduled.
3. Disable cron — hooks cleared on save; hourly run no-ops.

## Cleanup

1. Diagnostics → **Run cleanup now** (confirm dialog).
2. Verify automation run count drops for rows older than retention days.

## Concurrency

1. Run automation from Diagnostics twice quickly — second run should warn or skip overlap.
2. Check **Concurrency warnings** list.

## Release audit

```bash
bash scripts/release-audit.sh
bash scripts/build-zip.sh
bash scripts/verify-plugin.sh
```

## Checkout recording lock

1. Place a test order with an automatic promotion applied.
2. Confirm a single redemption row per `(order_id, promotion_id)` even if checkout hooks fire twice (lock is transient per order, 60s TTL).

## Remaining limitations

- 100+ active promotions increase planner CPU; narrow with orchestration groups and safe mode under load.
- Profiler aggregates are site-wide rolling options (not per-promotion time series).
- Cart/Checkout Blocks not declared compatible.
- PHPCS cleanup is incremental, not zero-violation.

## Smoke (WP-CLI)

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/performance-hardening-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-hardening-closure-smoke.php
```

## Release audit

```bash
bash scripts/release-audit.sh
```

Validates plugin header, required docs (including `docs/COMMERCIAL_READINESS.md`), schema version in ARCHITECTURE/README, and release zip excludes `vendor/` and `.git/`.
