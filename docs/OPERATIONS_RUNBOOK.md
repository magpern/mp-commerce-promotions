# Operations runbook

## Pre-deploy

1. `composer run lint:php` and `composer run test`
2. `./wp eval-file .../scripts/coupon-compatibility-smoke.php`
3. `./wp eval-file .../scripts/ga-stress-smoke.php`
4. Confirm `mp_cp_schema_version` matches `Schema::SCHEMA_VERSION`
5. Review **Diagnostics → Checkout certification** (nothing stale >30d for your release scope)

## Emergency controls (Diagnostics)

| Action | Effect |
|--------|--------|
| Safe mode | Disables automatic promotions |
| Disable line-item mode globally | Forces fee path only |
| Pause stackable | Pauses all stackable active promotions |
| Rebuild caches | Clears request planner/allocation caches |
| Clear planner telemetry | Truncates telemetry table (apply only) |
| Reset degraded mode | Clears planner failure degraded flag |

All support **Preview** (dry-run) before **Apply**.

## Support bundle

Diagnostics → Export support bundle — includes currency snapshot and coupon telemetry counters (no PII).
