# MP Commerce Promotions — Tasks

Living task list for development priorities. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design, [README.md](../README.md) for current MVP behavior, and [manual-stacking-test.md](manual-stacking-test.md) for stacking verification.

## Current milestone

**Quality & ops** — repository unit tests, remaining PHPCS batches, PHPCS gating in CI; follow-up browser checkout with non-crypto payment or manual order tools.

## Manual QA (2026-05-17)

- **Evidence:** [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md)
- **Browser:** Admin list/tabs/edit **pass**; storefront stacking/fees/checkout **partial** (see blockers in evidence doc).
- **Smokes:** All pass except `stacking-smoke.php` order assertions (integrity smoke covers stacked rows).
- **Blockers:** BTCPay checkout, CLI cart limits, variable gift product on live cart.

## Recently completed

- **Manual WooCommerce QA evidence** — `docs/MANUAL_QA_EVIDENCE.md`, README/TASKS QA status, smoke + composer verification on staging

- **Admin UX polish** — PromotionPicker, bulk status actions, exclusion checklist
- **Promotion reports** — Reports admin tab, `PromotionReports` service, redemption CSV export (5k cap), `scripts/reports-smoke.php`
- **Checkout redemption integrity** — `OrderPromotionState`, idempotent record/reverse/restore, `FreeGiftCartSynchronizer`, audit trace actions, smoke/docs
- **Redemption restrictions** — global/per-customer usage limits (schema 1.7.0), date enforcement, min/max cart quantity conditions, planner reason codes, smoke/docs
- **Free gift product action** — `free_gift_product` cart line + zero price hook; `FreeGiftCartHandler`; order recording; builder; `docs/manual-free-gift-test.md`; smoke `scripts/free-gift-smoke.php`
- **Cheapest item admin UX** — Simple Rule Builder; cart preview summaries; validator messages; `docs/manual-cheapest-item-test.md`
- **BOGO groundwork** — `CartItemSelector`, cart item enrichment, `cheapest_item_discount` action (negative fee MVP); smoke `scripts/cheapest-item-smoke.php`
- **Free shipping + customer usage rules** — `free_shipping` fee-offset action; `customer_redemption_count` condition; builder expansion; smoke `scripts/free-shipping-smoke.php`
- **Stacking behavior docs and smoke** — manual-stacking-test.md, stale copy cleanup, cap smoke at natural subtotal
- **Max applications enforcement** — planner plan cap, `max_applications_reached`, admin promotion plan preview
- **Promotion exclusion rules** — `excluded_promotion_ids` (schema 1.6.0), planner `excluded_by_selected_promotion`, admin field, list summary
- **Stackable cart fees** — multiple planner-selected promotions apply separate fees (subtotal cap); multi-redemption recording
- **Promotion stacking groundwork** — application_mode / stop_processing / max_applications, PromotionPlanner, admin application rules
- **WooCommerce HPOS compatibility** — `custom_order_tables` declaration; HPOS order-meta audit; blocks compatibility intentionally omitted
- **Evaluation trace / explainability** — `ConditionTrace`, `ActionTrace`, `EvaluationResult` traces, admin cart preview tables, PHPUnit coverage
- **Customer/location restrictions** — `billing_country`, `customer_email_domain`; Woo billing metadata enrichment
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
5. **Promotion mechanics (Phase C)** — free-product lines, line-price overrides, native shipping methods (see architecture roadmap)

## Backlog

### Admin UX

- Reusable admin table component (`WP_List_Table` migration)
- Product/category search in rule builder (no AJAX scope creep without design)
- Usage limit field on promotion edit form
- Screenshots for readme.txt

### Promotion engine

- Additional conditions (cart contents, tags, schedules)
- Additional actions (line-item targeting, native shipping integration)
- Additional promotion action types (BOGO, free product, line-item discounts)

### Storefront / WooCommerce

- Dedicated promotion code input (optional; coupon field works today)
- Partial refund handling for usage counters

### Quality

- GitHub Actions: syntax + PHPCS when baseline is manageable
- Commit `composer.lock` when CI is pinned
