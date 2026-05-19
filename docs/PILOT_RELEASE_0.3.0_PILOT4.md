# Pilot release: 0.3.0-pilot.4

**Plugin version:** `0.3.0-pilot.4`  
**Schema:** `1.19.0` (unchanged)  
**Tag:** `v0.3.0-pilot.4`  
**Type:** Packaging-only — supersedes **0.3.0-pilot.3**

## Summary

Production release ZIP hardening. No promotion-engine, admin UI, or database migration changes.

## Upgrade

| From | Action |
|------|--------|
| **0.3.0-pilot.3** | Replace plugin folder with this release ZIP (recommended over sync for production) |
| **0.3.0-pilot.2 or earlier** | Upgrade to **0.3.0-pilot.4** directly |

## Install

1. Download **`mp-commerce-promotions-0.3.0-pilot.4.zip`** from the GitHub Release or run `bash scripts/build-zip.sh` then `bash scripts/release-audit.sh`.
2. Upload via **Plugins → Add New → Upload**, or extract to `wp-content/plugins/mp-commerce-promotions/`.
3. Activate if needed; no new migration expected on schema **1.19.0**.

## Rollback

Deactivate and restore the previous plugin folder from backup, or install **0.3.0-pilot.3** ZIP if required.

## QA note

Smoke scripts are **not** in the production ZIP. Run them from the Git repository checkout or staging tree only. See [QA_SCRIPT_SAFETY.md](QA_SCRIPT_SAFETY.md).
