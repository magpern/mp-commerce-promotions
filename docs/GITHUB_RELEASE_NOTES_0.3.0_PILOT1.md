# Commerce Promotions for WooCommerce 0.3.0-pilot.1

**Pilot release** — staged merchant pilots only. Not GA or marketplace-ready.

## Highlights

- **Campaign Builder** — guided wizard, 10 campaign goals, draft creation, merchant summaries, product/category AJAX search
- **Default entrypoint** — WooCommerce → Promotions opens Campaign Builder; **Advanced Promotions** for expert mode
- **Create campaign** shortcuts on Getting Started, Advanced Promotions, Reports, and Diagnostics (empty store)

## Checkout compatibility

- **Classic** shortcode cart/checkout — supported
- **Cart/Checkout Blocks** — supported (`cart_checkout_blocks` declared; fee + coupon paths certified)
- **HPOS** — declared compatible

## Operations

- Reports, Diagnostics, support bundle export
- Safe mode, degraded storefront mode, emergency operations panel
- Schema **1.17.0** (no migration in this release)

## Known limitations

- **Line item / hybrid** discount modes remain **experimental** (fee-based default)
- **PHPCS** in CI is **advisory only** (non-blocking)
- Pilot software — review [docs/PILOT_RELEASE_0.3.0_PILOT1.md](docs/PILOT_RELEASE_0.3.0_PILOT1.md) before production traffic

## Installation

1. Download **`mp-commerce-promotions-0.3.0-pilot.1.zip`** from this release.
2. Install via **Plugins → Add New → Upload** or extract to `wp-content/plugins/mp-commerce-promotions/`.
3. Activate and open **WooCommerce → Promotions** (Campaign Builder).

## Rollback

Deactivate the plugin and restore the previous plugin folder from backup. Database schema unchanged at **1.17.0** for this release.

## Documentation

- [PILOT_RELEASE_0.3.0_PILOT1.md](docs/PILOT_RELEASE_0.3.0_PILOT1.md)
- [CAMPAIGN_BUILDER_QA_EVIDENCE.md](docs/CAMPAIGN_BUILDER_QA_EVIDENCE.md)
- [CHANGELOG.md](CHANGELOG.md)
