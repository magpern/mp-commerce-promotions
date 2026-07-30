# Commerce Promotions 0.5.2

Fixes a gift card display bug: the customer-entered amount could show as **0** in any live per-item price display (e.g. a mini-cart) whenever the cart was restored from session without a full totals recalculation — most visible right after switching currency.

## What changed

- **Root cause** — the gift card's cart-item price override only lived in memory on the `WC_Product` object for one request, and was only reapplied inside `woocommerce_before_calculate_totals`. That hook isn't guaranteed to fire on every page view, so a fresh page load could start from the product's real (empty) price.
- **Fix** — `GiftCardCustomerAmountCart` now also reapplies the stored amount via `woocommerce_get_cart_item_from_session`, the moment the cart item is restored from session, on every request — independent of whether `calculate_totals()` runs. The existing `woocommerce_before_calculate_totals` hook is kept for correct fee/discount/tax computation.
- **Invariant unchanged** — product meta, cart, and ledger still store the gift card face value in **shop base currency**.

**Database schema:** unchanged at **1.19.0**.

## Upgrade

| From | Action |
|------|--------|
| **0.5.1** | Use **Plugins → Update** on production, or upload this ZIP |
| **0.5.0 or older** | Upgrade normally; no schema changes since 0.4.0 |
| **Dev/staging** (repo/sync) | Keep `MP_CP_DISABLE_GITHUB_UPDATER`; pull/sync as usual |

## Install

1. Download **`mp-commerce-promotions-0.5.2.zip`** from this release.
2. Upload via **Plugins → Add New → Upload** or extract to `wp-content/plugins/mp-commerce-promotions/`.

## Rollback

Deactivate and restore the previous plugin folder (e.g. **0.5.1** ZIP) from backup; keep a database backup.

Full changelog: [CHANGELOG.md](../CHANGELOG.md)
