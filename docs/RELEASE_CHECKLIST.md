# Release checklist

Use this list when cutting a **plugin release** (not a database schema migration). The plugin is still early MVP — treat releases as tagged snapshots for deployment and review, not as a claim of production readiness.

See [CHANGELOG.md](../CHANGELOG.md) for user-facing release notes and [DEVELOPMENT.md](DEVELOPMENT.md) for day-to-day workflow.

## Pre-release (staging tree)

- [ ] Git working tree clean (`git status` shows no uncommitted changes you intend to ship).
- [ ] On intended branch (usually `main`).
- [ ] **GitHub Actions CI** passes on `main` for the commit you will tag (`.github/workflows/ci.yml` — syntax lint, PHPUnit, build zip; PHPCS **advisory only**).
- [ ] For tagged releases: `.github/workflows/release.yml` runs on `v*` push (lint, test, zip, GitHub Release asset).
- [ ] [PILOT_RELEASE_0.3.0_PILOT1.md](PILOT_RELEASE_0.3.0_PILOT1.md) reviewed for pilot audience.
- [ ] `composer install` (dev dependencies for lint only).
- [ ] `composer run lint:php` — must pass (syntax).
- [ ] `composer run lint:phpcs` — record counts in release notes; **non-blocking** in CI (see [PHPCS_BASELINE.md](PHPCS_BASELINE.md)).
- [ ] `bash scripts/build-zip.sh` — produces `../build/mp-commerce-promotions-{version}.zip`.
- [ ] `bash scripts/release-audit.sh` — repo + artifact checks pass.
- [ ] Inspect zip: **production artifact only** — no `scripts/`, `tests/`, `docs/`, `.github/`, `.git/`, `vendor/`, `node_modules/`, `build/`, composer/PHPCS/PHPUnit dev files (see `scripts/lib/verify-release-zip.py` and script summary output).
- [ ] Zip includes `src/Infrastructure/GithubUpdater.php` (production update channel).
- [ ] `docs/GITHUB_RELEASE_NOTES_{version}.md` present for the tag (e.g. `docs/GITHUB_RELEASE_NOTES_0.4.0.md`).
- [ ] Dev/staging: document `MP_CP_DISABLE_GITHUB_UPDATER` in `wp-config.php` when using repo/sync (not ZIP-only deploy).

## Deploy to local live (this VPS)

- [ ] `bash scripts/sync-to-live.sh` — never copies `.git` or `vendor` to live.
- [ ] `bash scripts/verify-plugin.sh` — deactivate/activate cycle and `mp_cp_schema_version` printed.

## Beta certification checklist

- [ ] [BETA_READINESS.md](BETA_READINESS.md) reviewed.
- [ ] [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md) — blocks declared; re-verify before GA if checkout stack changes.
- [ ] `./wp eval-file .../scripts/beta-readiness-smoke.php` passes.
- [ ] `languages/mp-commerce-promotions.pot` regenerated if admin strings changed.

## Commercial beta checklist

- [ ] [COMMERCIAL_READINESS.md](COMMERCIAL_READINESS.md) reviewed for release audience.
- [ ] Getting Started tab loads (`tab=getting-started`).
- [ ] Settings save/load; feature gates behave (telemetry, CSV, simulations, gift/shipping).
- [ ] Compatibility status visible on Reports and Diagnostics.
- [ ] Support bundle downloads from Diagnostics (no PII in JSON).
- [ ] `./wp eval-file .../scripts/commercial-readiness-smoke.php` passes.

## Compatibility checklist

- [ ] WooCommerce active; HPOS state matches merchant expectation.
- [ ] Cart/Checkout Blocks **not** declared — document for merchants using blocks.
- [ ] Fee-based discount strategy confirmed in Compatibility status.

## Uninstall / data retention checklist

- [ ] Default: **retain data** on uninstall (`mp_cp_delete_data_on_uninstall` not set or `no`).
- [ ] Opt-in deletion tested only on disposable environment.
- [ ] readme.txt and Settings warn before enabling delete-on-uninstall.

## Localization checklist

- [ ] `languages/mp-commerce-promotions.pot` present (6000+ lines from `wp i18n make-pot`; see [BETA_READINESS.md](BETA_READINESS.md)).
- [ ] New admin strings use `__()` / `esc_html__()` with text domain `mp-commerce-promotions`.

## Browser QA checklist

- [ ] [BROWSER_QA_RUNBOOK.md](BROWSER_QA_RUNBOOK.md) — gateways (COD on local Docker), products, promotions, personas.
- [ ] [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md) — browser sign-off table completed.
- [ ] [BLOCK_CHECKOUT_INVESTIGATION.md](BLOCK_CHECKOUT_INVESTIGATION.md) — block test pages exercised before declaring blocks.
- [ ] [RELEASE_EVIDENCE_0.2.0_BETA1.md](RELEASE_EVIDENCE_0.2.0_BETA1.md) — evidence bundle updated.
- [ ] `./wp eval-file .../scripts/beta-release-prep-smoke.php` passes.
- [ ] [VERSION_BUMP_PLAN_0.2.0_BETA1.md](VERSION_BUMP_PLAN_0.2.0_BETA1.md) — do not tag until browser QA approved.

- [ ] Promotions nav tabs (Getting Started, Campaign Builder, Advanced Promotions, Reports, Diagnostics, Settings).
- [ ] `./wp eval-file .../scripts/pilot-release-smoke.php` passes.
- [ ] `./wp eval-file .../scripts/campaign-builder-smoke.php` passes.
- [ ] Promotion edit: cart preview, templates, save.
- [ ] Reports filters and sections (simulation hidden when disabled).

## PHPCS status

- [ ] `composer run lint:phpcs` — record error/warning count in release notes (not CI-gating unless policy changes).
- [ ] `composer run lint:php` — must pass.

## Manual regression (MVP)

- [ ] [manual-checkout-test.md](manual-checkout-test.md) — cart fee, redemption, reversal.
- [ ] [manual-promotion-code-test.md](manual-promotion-code-test.md) — coupon field, batches, code lifecycle.

## Version bump (when releasing a new plugin version)

Update **the same version string** in all of:

- [ ] `mp-commerce-promotions.php` — `Version:` plugin header.
- [ ] `mp-commerce-promotions.php` — `MP_COMMERCE_PROMOTIONS_VERSION` constant.
- [ ] `readme.txt` — `== Changelog ==` section for the new release; set `Stable tag:` when publishing to WordPress.org (currently `trunk` during active MVP development).
- [ ] [CHANGELOG.md](../CHANGELOG.md) — move items from `[Unreleased]` to `[X.Y.Z]` with date.

Do **not** change `Schema::SCHEMA_VERSION` unless you have a deliberate schema migration (out of scope for a packaging-only release).

## Git tag and publish

- [ ] Commit version and changelog updates.
- [ ] Create annotated tag: `git tag -a vX.Y.Z -m "Release X.Y.Z"`
- [ ] `git push origin main`
- [ ] `git push origin vX.Y.Z`
- [ ] Push tag `vX.Y.Z` — release workflow uploads artifact and attaches `mp-commerce-promotions-X.Y.Z.zip` (prerelease when version contains `pilot`, `beta`, or `rc`).
- [ ] Confirm GitHub Release asset and workflow run succeeded.

## Package contents reminder

**Production ZIP** (`scripts/build-zip.sh`) **includes**:

- `mp-commerce-promotions.php`, `src/`, `assets/`, `languages/`
- `readme.txt`, `CHANGELOG.md`, `LICENSE`, `uninstall.php`

**Production ZIP excludes** (remain in the Git repo only):

- `scripts/`, `tests/`, `docs/`, `.github/`
- `.git/`, `vendor/`, `node_modules/`, `build/`
- `.phpcs-cache`, `.phpunit.result.cache`
- `composer.json`, `composer.lock`, `composer.phar`, `phpcs.xml.dist`, `phpunit.xml.dist`
- `README.md` (developer readme; merchant-facing text is in `readme.txt`)
- `.env*`, `*.log`, `*.sql`, and other local/cache files

QA/smoke scripts run from the **repository** or staging sync tree via WP-CLI — not from the distributed ZIP. See [QA_SCRIPT_SAFETY.md](QA_SCRIPT_SAFETY.md).
