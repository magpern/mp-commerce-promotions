# MP Commerce Promotions — Tasks

Living task list for development priorities. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design and [README.md](../README.md) for current MVP behavior.

## Current milestone

**Quality & ops** — repository unit tests, remaining PHPCS batches, PHPCS gating in CI.

## Recently completed

- **Customer context enrichment** — Woo `has_previous_orders` + `customer_roles` metadata; `customer_role` condition
- **Customer/order conditions + engine tests** — `logged_in`, `first_order` (metadata); expanded PHPUnit for evaluator, validator, builder, domain
- **PHPUnit unit scaffold** — `phpunit.xml.dist`, `tests/Unit/*`, `composer run test` in CI; no WordPress bootstrap yet
- **CI baseline** — `.github/workflows/ci.yml` (PHP 7.4/8.1/8.2 syntax lint + build zip); PHPCS enforcement deferred
- **Release packaging** — `CHANGELOG.md`, `docs/RELEASE_CHECKLIST.md`, `scripts/build-zip.sh`; version `0.1.0` aligned across header/constant/readme
- **Safe sync scripts** — `scripts/sync-to-live.sh`, `scripts/verify-plugin.sh`; workflow in [DEVELOPMENT.md](DEVELOPMENT.md)
- **Rule registry groundwork** — `RuleTypes`, `RuleRegistry`; validator/builder/evaluator wired to central type IDs
- **Woo layer hardening** — `CartSessionHelper`, `WooCommerceBridge` hook summary, PHPCS cleanup on `src/Woo/*`, manual test doc updates
- **PHPCS repository batch** — `TableName`, `DbQuery`, repository SQL hardening (see [DEVELOPMENT.md](DEVELOPMENT.md))
- **Reusable admin infrastructure** — `AdminNotice`, `AdminSection`, `AdminUrl`; edit/list/settings/diagnostics refactors
- **PHPCS cleanup batch #1** — admin alignment/escaping (see [DEVELOPMENT.md](DEVELOPMENT.md))
- **Code quality tooling** — `composer.json`, `phpcs.xml.dist`, `docs/DEVELOPMENT.md`
- Admin UX bundle: duplicate promotion, edit screen layout, standardized notices
- Simple Rule Builder v0 and product/category ID helper
- Rule templates, validation panel, list search/filters/pagination
- Promotion codes, batches, redemptions, reversal, diagnostics
- Architecture guide (`docs/ARCHITECTURE.md`)
- Marketplace readiness scaffolding

## Next planned

1. **PHPUnit expansion** — repositories, duplication service (still no WP integration suite)
2. **PHPCS / WPCS baseline** — remaining packages; then enable `lint:phpcs` as CI gate
3. **Composer autoloading** — optional PSR-4 via Composer for releases
5. **Promotion mechanics (Phase C)** — free shipping, BOGO, stackability (see architecture roadmap)

## Backlog

### Admin UX

- Reusable admin table component (`WP_List_Table` migration)
- Product/category search in rule builder (no AJAX scope creep without design)
- Usage limit field on promotion edit form
- Screenshots for readme.txt

### Promotion engine

- Additional conditions (first order, customer role, country)
- Additional actions (free shipping, line-item targeting)
- Stackability and conflict resolution beyond “first eligible wins”

### Storefront / WooCommerce

- Dedicated promotion code input (optional; coupon field works today)
- Partial refund handling for usage counters

### Quality

- GitHub Actions: syntax + PHPCS when baseline is manageable
- Commit `composer.lock` when CI is pinned
