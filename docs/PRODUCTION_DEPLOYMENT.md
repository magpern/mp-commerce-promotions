# Production deployment

1. **Production:** deploy the GitHub Release ZIP from `scripts/build-zip.sh` (verified with `scripts/release-audit.sh`). The ZIP does not include `scripts/` — run smokes from the Git repo or staging tree only. **Dev/staging:** `scripts/sync-to-live.sh` is acceptable but never copy `.git/` or `vendor/` to live.
2. Deactivate/activate once to run migrations (`1.17.0` adds `mp_cp_certification_runs`)
3. Verify: `bash scripts/verify-plugin.sh`
4. Smoke on production clone when possible (read-only smokes need no flags; data-creating smokes require `MP_CP_ALLOW_LIVE_QA=1` — see [QA_SCRIPT_SAFETY.md](QA_SCRIPT_SAFETY.md)):
   - `qa-runtime-guard-smoke.php`
   - `coupon-compatibility-smoke.php`
   - `blocks-compatibility-smoke.php` (if blocks in scope)
5. Record certification rows for classic/blocks/coupon/line as applicable
6. Enable planner telemetry unless high-volume store requires pause

Do not enable global dry-run in production unless testing.
