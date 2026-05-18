# Incident response

## Runaway discounts

1. Enable **Safe mode** (Settings or Emergency operations)
2. Enable **Promotion dry-run (global)** to evaluate without applying fees
3. Export support bundle
4. Check **Coupon coexistence telemetry** and planner slow runs

## Checkout fatals

1. **Reset degraded mode**
2. Disable **line-item mode globally**
3. Check WooCommerce logs; verify `LineDiscountFallbackTelemetry` stats

## Stale certification

If **Checkout certification** shows stale (>30d), re-run browser/CLI certification before blaming new regressions.

## Load issues

Run `scripts/load-harness.php` on staging with production promotion count export; compare p95 runtime to prior baseline.
