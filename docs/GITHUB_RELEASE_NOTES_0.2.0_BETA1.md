# Commerce Promotions for WooCommerce 0.2.0-beta.1

**Technical beta** — first public pre-release for pilot stores. Not a general-availability or marketplace-certified production release.

## Compatibility

| Area | Status |
|------|--------|
| **Storefront** | **Classic shortcode** cart and checkout only (certified enough for technical beta) |
| **HPOS** | **Declared compatible** (`custom_order_tables`) |
| **Cart/Checkout Blocks** | **Not declared** — block cart/checkout behavior is unverified; use classic pages or test at your own risk |
| **Database schema** | **1.14.0** (unchanged from prior milestones; no new migration in this tag) |

## Highlights

- Rule-driven promotion engine: conditions, actions, stacking, exclusions, promotion codes, and operational admin tools.
- Storefront discounts via **negative cart fees** (percentage, fixed, free shipping offset, cheapest-item) and **free gift** cart lines — not native WooCommerce line-item or coupon discounts.
- Checkout **redemption recording** with idempotent record/reverse; **fix** when session is empty at order creation (fee-line rebuild + `woocommerce_checkout_order_processed` retry).
- **Classic browser QA** (COD): stacked fees, order placement, recording, and cancellation reversal documented in the repository.
- Production hardening, simulation/forecasting, orchestration, economics, pricing groundwork, reports, diagnostics, and beta readiness documentation since `0.1.0` (see [CHANGELOG.md](../CHANGELOG.md)).

## Known caveats

- **Audience:** Technical pilot users and staging clones — not recommended for production-only block checkout or untested payment flows.
- **Browser QA:** Stacked checkout, COD checkout, recording, and reversal **passed**; scoped percentage, free gift line, free shipping with paid shipping, budget/cooldown, and CSV export are **partial** or **not run** ([CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md)).
- **Discount model:** Fee-based; promotion codes use virtual coupons with zero native discount — fees/gifts come from this plugin.
- **PHPCS:** Baseline not clean; CI runs PHPCS with `continue-on-error` (informational only).
- **Partial refund reversal:** Not supported in this release.

## Rollback and data

1. Deactivate the plugin or enable **Safe mode** and disable cart discounts in settings.
2. Install the previous release zip or check out tag `v0.1.0`.
3. **Data retention:** Uninstall defaults to **retaining** promotion tables and redemptions unless you opt in to delete data. See [BETA_READINESS.md](BETA_READINESS.md).

## Install

1. Download **`mp-commerce-promotions-0.2.0-beta.1.zip`** from this release.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → activate.
3. Requires **WooCommerce**; run your own smoke test on a **staging** clone with **classic** cart/checkout pages.

## Artifact

- **File:** `mp-commerce-promotions-0.2.0-beta.1.zip`
- **Built from tag:** `v0.2.0-beta.1`

Full changelog: https://github.com/magpern/mp-commerce-promotions/blob/v0.2.0-beta.1/CHANGELOG.md  
Release evidence: https://github.com/magpern/mp-commerce-promotions/blob/v0.2.0-beta.1/docs/RELEASE_EVIDENCE_0.2.0_BETA1.md
