# Commerce Promotions 0.5.0

Gift card amounts work correctly with WOOCS multi-currency browsing while checkout/payment stay in the shop base currency.

## What changed

- **Storefront display** — min / suggested / customer-entered gift card amounts convert via WOOCS; min and suggested round up to the nearest 10 in the display currency.
- **Base currency storage** — cart and order meta store the gift card face value in shop base currency (e.g. EUR), even when the shopper browsed in another currency.
- **Fixes**
  - Checkout crash when persisting gift card line item meta
  - Cart total truncation when WOOCS currency decimals are missing
  - Double conversion on displayed gift card amounts
  - Missing `GiftCard` import causing HTTP 500 on add-to-cart

**Database schema:** unchanged at **1.19.0**.

## Upgrade

| From | Action |
|------|--------|
| **0.4.0** | Use **Plugins → Update** on production, or upload this ZIP |
| **Dev/staging** (repo/sync) | Keep `MP_CP_DISABLE_GITHUB_UPDATER`; pull/sync as usual |

## Install

1. Download **`mp-commerce-promotions-0.5.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload** or extract to `wp-content/plugins/mp-commerce-promotions/`.

## Rollback

Deactivate and restore the previous plugin folder (e.g. **0.4.0** ZIP) from backup; keep a database backup.

Full changelog: [CHANGELOG.md](../CHANGELOG.md)
