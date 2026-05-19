# Production deployment

1. **Production:** install or update from the [GitHub Release ZIP](https://github.com/magpern/mp-commerce-promotions/releases/latest) (`mp-commerce-promotions-X.Y.Z.zip`), or use **Dashboard → Updates** when `WP_ENVIRONMENT_TYPE` is `production` and the GitHub updater is enabled. Local builds: `scripts/build-zip.sh` + `scripts/release-audit.sh`. The ZIP does not include `scripts/` — run smokes from the Git repo or staging tree only.
2. **Dev/staging:** use `scripts/sync-to-live.sh` or repo checkout; set `define( 'MP_CP_DISABLE_GITHUB_UPDATER', true );` in `wp-config.php` so production update prompts do not appear on non-production environments.
3. Deactivate/activate once to run migrations if upgrading across schema versions (current schema **1.19.0**)
4. Verify: `bash scripts/verify-plugin.sh`
5. Smoke on production clone when possible (read-only smokes need no flags; data-creating smokes require `MP_CP_ALLOW_LIVE_QA=1` — see [QA_SCRIPT_SAFETY.md](QA_SCRIPT_SAFETY.md)):
   - `qa-runtime-guard-smoke.php`
   - `coupon-compatibility-smoke.php`
   - `blocks-compatibility-smoke.php` (if blocks in scope)
6. Record certification rows for classic/blocks/coupon/line as applicable
7. Enable planner telemetry unless high-volume store requires pause

Do not enable global dry-run in production unless testing.
