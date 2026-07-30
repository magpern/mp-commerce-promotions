# Commerce Promotions 0.5.1

Gift card amounts now convert correctly with **Universal Multicurrency** (dev/staging) as well as WOOCS. Any other currency switcher can plug in via WordPress filters without forking this plugin.

## What changed

- **Universal Multicurrency** — built-in adapter uses UMC rates and decimals for gift card min/suggested/default display and for converting customer input back to shop base currency.
- **Converter-agnostic hooks**
  - `mp_cp_gift_card_convert_base_to_display` — return a numeric display amount or `null` to defer
  - `mp_cp_gift_card_convert_display_to_base` — return a numeric base amount or `null` to defer
- **Resolution order** — custom filters → WOOCS → Universal Multicurrency → no conversion (same base/display currency only).
- **Invariant unchanged** — product meta, cart, and ledger store gift card face value in **shop base currency** (e.g. EUR); storefront shows the active browse currency.

**Database schema:** unchanged at **1.19.0**.

## Upgrade

| From | Action |
|------|--------|
| **0.5.0** | Use **Plugins → Update** on production, or upload this ZIP |
| **0.4.0 or older** | Upgrade to **0.5.0** first if you rely on gift card multi-currency, then to **0.5.1** |
| **Dev/staging** (repo/sync) | Keep `MP_CP_DISABLE_GITHUB_UPDATER`; pull/sync as usual |

## Install

1. Download **`mp-commerce-promotions-0.5.1.zip`** from this release.
2. Upload via **Plugins → Add New → Upload** or extract to `wp-content/plugins/mp-commerce-promotions/`.

## Rollback

Deactivate and restore the previous plugin folder (e.g. **0.5.0** ZIP) from backup; keep a database backup.

Full changelog: [CHANGELOG.md](../CHANGELOG.md)
