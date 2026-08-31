# Commerce Promotions 0.5.3

Adds **Promotions** and **Settings** links on the WordPress **Plugins** screen for quick access to Commerce Growth admin.

## What changed

- **Plugins screen** — users with `manage_woocommerce` see **Promotions** (Advanced Promotions list) and **Settings** beside **Deactivate**.
- **No functional changes** to promotion evaluation, gift cards, or cart behavior.

**Database schema:** unchanged at **1.19.0**.

## Upgrade

| From | Action |
|------|--------|
| **0.5.2** | Use **Plugins → Update** on production, or upload this ZIP |
| **0.5.1 or older** | Upgrade normally; no schema changes since 0.4.0 |
| **Dev/staging** (repo/sync) | Keep `MP_CP_DISABLE_GITHUB_UPDATER`; pull/sync as usual |

## Install

1. Download **`mp-commerce-promotions-0.5.3.zip`** from this release.
2. Upload via **Plugins → Add New → Upload** or extract to `wp-content/plugins/mp-commerce-promotions/`.

## Rollback

Deactivate and restore the previous plugin folder (e.g. **0.5.2** ZIP) from backup; keep a database backup.

Full changelog: [CHANGELOG.md](../CHANGELOG.md)
