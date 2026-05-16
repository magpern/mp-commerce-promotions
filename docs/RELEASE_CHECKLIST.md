# Release checklist

Use this list when cutting a **plugin release** (not a database schema migration). The plugin is still early MVP — treat releases as tagged snapshots for deployment and review, not as a claim of production readiness.

See [CHANGELOG.md](../CHANGELOG.md) for user-facing release notes and [DEVELOPMENT.md](DEVELOPMENT.md) for day-to-day workflow.

## Pre-release (staging tree)

- [ ] Git working tree clean (`git status` shows no uncommitted changes you intend to ship).
- [ ] On intended branch (usually `main`).
- [ ] `composer install` (dev dependencies for lint only).
- [ ] `composer run lint:php` — must pass (syntax).
- [ ] `composer run lint:phpcs` — **optional for now**; full plugin baseline is **not clean** (see [DEVELOPMENT.md](DEVELOPMENT.md)). Do not block a release solely on a zero-violation PHPCS run until CI policy says so.
- [ ] `bash scripts/build-zip.sh` — produces `../build/mp-commerce-promotions-{version}.zip`.
- [ ] Inspect zip: no `.git/`, `vendor/`, `node_modules/`, or cache directories (see script output / `unzip -l`).

## Deploy to local live (this VPS)

- [ ] `bash scripts/sync-to-live.sh` — never copies `.git` or `vendor` to live.
- [ ] `bash scripts/verify-plugin.sh` — deactivate/activate cycle and `mp_cp_schema_version` printed.

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
- [ ] Attach `mp-commerce-promotions-X.Y.Z.zip` to GitHub release (optional).

## Package contents reminder

The zip from `scripts/build-zip.sh` includes plugin source, `docs/`, `scripts/`, `languages/`, `README.md`, `readme.txt`, `LICENSE`, `CHANGELOG.md`, `composer.json`, and `phpcs.xml.dist`.

It **excludes**: `.git`, `vendor`, `node_modules`, `.phpcs-cache`, `.phpunit.result.cache`, and `build/`.
