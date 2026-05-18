# Commerce Promotions for WooCommerce 0.3.0-pilot.2

**Pilot prerelease** — supersedes **0.3.0-pilot.1**. Use this build for all new pilots.

## Supersedes pilot.1

**0.3.0-pilot.1 is superseded.** Do not deploy pilot.1.

| Issue | Fix in pilot.2 |
|-------|----------------|
| Opening **WooCommerce → Promotions** without `tab` showed Campaign Builder nav but **unstyled / broken** builder UI | Asset enqueue now uses normalized tab; default URL matches `tab=campaign-builder` |

## Highlights

- **Campaign Builder** — default entrypoint, wizard, 10 goals, draft creation
- **Routing fix** — `?page=mp-commerce-promotions` renders the same as `&tab=campaign-builder`
- **Advanced Promotions** — expert mode unchanged
- **Schema 1.17.0** — no migration

## Checkout compatibility

- Classic shortcode cart/checkout — supported
- Cart/Checkout Blocks — supported (`cart_checkout_blocks` declared)
- HPOS — declared compatible

## Known limitations

- Line item / hybrid discount modes — **experimental**
- PHPCS — **advisory only** in CI
- Pilot software — not GA

## Installation

1. Download **`mp-commerce-promotions-0.3.0-pilot.2.zip`** from this release.
2. Install and activate; open **WooCommerce → Promotions**.
3. Verify Campaign Builder loads with full styling (no `tab` query arg required).

## Upgrade from pilot.1

Replace the plugin folder or upload this ZIP over pilot.1. No database migration required.

## Documentation

- [PILOT_RELEASE_0.3.0_PILOT2.md](docs/PILOT_RELEASE_0.3.0_PILOT2.md)
- [CHANGELOG.md](CHANGELOG.md)
