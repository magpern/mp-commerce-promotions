# Production deployment

1. Deploy plugin via `scripts/sync-to-live.sh` or release zip from `scripts/build-zip.sh`
2. Deactivate/activate once to run migrations (`1.17.0` adds `mp_cp_certification_runs`)
3. Verify: `bash scripts/verify-plugin.sh`
4. Smoke on production clone when possible:
   - `coupon-compatibility-smoke.php`
   - `blocks-compatibility-smoke.php` (if blocks in scope)
5. Record certification rows for classic/blocks/coupon/line as applicable
6. Enable planner telemetry unless high-volume store requires pause

Do not enable global dry-run in production unless testing.
