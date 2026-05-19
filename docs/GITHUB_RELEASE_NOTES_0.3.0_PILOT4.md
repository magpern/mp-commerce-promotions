# Commerce Promotions for WooCommerce 0.3.0-pilot.4

**Packaging-only pilot release** — supersedes **0.3.0-pilot.3**. No promotion-engine behavior changes. Database schema **1.19.0** unchanged.

## What changed

- Production ZIP excludes `scripts/`, `tests/`, `docs/`, `.github/`, and other dev-only paths.
- `scripts/release-audit.sh` and `scripts/lib/verify-release-zip.py` verify artifact cleanliness before release.

## Upgrade

| Item | Detail |
|------|--------|
| **From 0.3.0-pilot.3** | Replace plugin folder with this ZIP |
| **Schema** | No migration required if already on ≥ 1.19.0 |

## Install

1. Download **`mp-commerce-promotions-0.3.0-pilot.4.zip`** from this release.
2. Install and activate; open **WooCommerce → Commerce Growth**.
3. Optional: run smoke scripts from the **repository** checkout (not from the ZIP).

## Rollback

1. Deactivate (optional backup).
2. Replace the plugin directory with **0.3.0-pilot.3** or earlier backup ZIP.

Full changelog: [CHANGELOG.md](../CHANGELOG.md)
