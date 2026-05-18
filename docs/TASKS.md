# MP Commerce Promotions — Tasks

Living task list for development priorities. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design, [README.md](../README.md) for current MVP behavior, and [manual-stacking-test.md](manual-stacking-test.md) for stacking verification.

## Current milestone

**Pilot release 0.3.0-pilot.1** — Campaign Builder default entrypoint, pilot docs, GitHub Actions release ZIP on `v*` tags. See [PILOT_RELEASE_0.3.0_PILOT1.md](PILOT_RELEASE_0.3.0_PILOT1.md).

**Line discount stabilization** (complete) — fee-based default; line/hybrid experimental per `docs/manual-line-discount-engine-test.md`.

## Manual QA (2026-05-17)

- **Evidence:** [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md)
- **Browser:** Admin list/tabs/edit **pass**; storefront stacking/fees/checkout **partial** (see blockers in evidence doc).
- **Smokes:** All pass except `stacking-smoke.php` order assertions (integrity smoke covers stacked rows).
- **Blockers:** BTCPay checkout, CLI cart limits, variable gift product on live cart.

## Recently completed

- **0.3.0-pilot.1 packaging** — version bump, pilot + GitHub release notes, `pilot-release-smoke.php`, `release.yml`, build-zip root-folder validation
- **Campaign Builder default entrypoint** — Promotions menu opens Campaign Builder; Advanced Promotions tab for expert mode; Create campaign shortcuts across admin
- **Campaign Builder QA pass** — all 10 goals, smoke expansion, UX/a11y polish (`docs/CAMPAIGN_BUILDER_QA_EVIDENCE.md`)
- **Merchant Campaign Builder** — `tab=campaign-builder`, goal→template mapping, draft creation, preview warnings, smoke + `docs/manual-campaign-builder-test.md` (no new engine behavior)
- **Browser QA beta release prep** — runbook, classic certification, block draft pages, release evidence, beta-release-prep smoke
- **Beta readiness certification** — BETA_READINESS, blocks doc (not declared), POT, PHPCBF on staging, CI PHPCS soft gate, beta smoke
- **Production hardening closure** — Reports production dashboard, checkout recording lock, redemption memoization, simulation request cache, expanded `release-audit.sh`, closure smoke/tests, docs
- **Performance and production hardening** — profiler, concurrency, optional cron, safe/degraded mode, retention cleanup, compatibility confidence
- **Commercial readiness** — Getting Started, settings governance, compatibility panel, support bundle, uninstall opt-in
- **Orchestration and segmentation (schema 1.11.0)** — cooldown/orchestration fields, snapshots, segmentation conditions, planner explainability, conflict congestion, reports, templates, admin UX, diagnostics automation, PHPUnit + smoke/docs
- **Economics and scheduling (schema 1.10.0)** — promotion budgets, batch export metadata, lifecycle filters/badges, schedule analyzer, reports economics, diagnostics pause exhausted, PHPUnit + smoke/docs
- **Campaign operations (schema 1.9.0)** — campaign_label/internal_notes/admin_color, admin UI, list/report filters, Diagnostics archive hygiene, smoke/docs
- **Conflict analysis and planner explainability** — `PromotionConflictAnalyzer`, `PromotionPlanExplainer`, admin preview + list indicators, validator heuristics, PHPUnit + `scripts/conflict-analysis-smoke.php`, `docs/manual-conflict-analysis-test.md`
- **Promotion templates** — `PromotionTemplate` presets, admin apply flow, smoke/docs (no new engine types)
- **Scoped discount calculations** — `EligibleCartScope`, eligible subtotal conditions, scoped percentage/fixed previews, cheapest item refactor, smoke/docs
- **Product targeting (schema 1.8.0)** — variation-aware `CartItemSelector`, `product_in_cart` / `category_in_cart` / `exclude_sale_items`, promotion product/category exclusions, cheapest item `variation_ids` + sale pool exclusion, admin/builder, `scripts/product-targeting-smoke.php`, `docs/manual-product-targeting-test.md`
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
- **CI baseline** — `.github/workflows/ci.yml` (PHP 8.1/8.2/8.3 syntax lint + build zip); PHPCS enforcement deferred
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
