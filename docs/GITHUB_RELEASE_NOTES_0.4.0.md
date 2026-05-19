# Commerce Promotions 0.4.0

**First stable release line** — production installs and updates from GitHub Release ZIP assets.

## What changed

- **`src/Infrastructure/GithubUpdater.php`** — checks [latest GitHub Release](https://github.com/magpern/mp-commerce-promotions/releases/latest) and installs asset **`mp-commerce-promotions-X.Y.Z.zip`** (not source archives).
- **Environment guard** — updater runs on `WP_ENVIRONMENT_TYPE=production` by default; disable on dev/staging with `MP_CP_DISABLE_GITHUB_UPDATER` in `wp-config.php`.
- **Prerelease installs** — sites on `-pilot`, `-dev`, `-beta`, `-rc`, etc. are not prompted to downgrade; updates offered only when latest stable is newer than the install base version.
- **Packaging** — production ZIP remains runtime-only (`src/`, `assets/`, `languages/`; no `scripts/`, `tests/`, `docs/`, `.github/`).

**Database schema:** unchanged at **1.19.0** (no migration beyond normal activate/upgrade hooks).

## Upgrade

| From | Action |
|------|--------|
| **0.3.0-pilot.4** (or earlier pilot) | Use **Plugins → Update** on production, or upload this ZIP |
| **Dev/staging** (repo/sync) | Keep `MP_CP_DISABLE_GITHUB_UPDATER`; deploy ZIP to production only when ready |

## Install

1. Download **`mp-commerce-promotions-0.4.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload** or extract to `wp-content/plugins/mp-commerce-promotions/`.
3. Deactivate/activate once if upgrading from an older pilot to run schema checks.

## Rollback

Deactivate and restore the previous plugin folder (e.g. **0.3.0-pilot.4** ZIP) from backup; keep a database backup.

Full changelog: [CHANGELOG.md](../CHANGELOG.md)
