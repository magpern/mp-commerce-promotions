# Manual test: performance and production hardening

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

## Smoke (WP-CLI)

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/performance-hardening-smoke.php
```
