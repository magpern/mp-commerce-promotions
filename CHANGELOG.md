# Changelog

All notable changes to **Commerce Promotions for WooCommerce** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) for the **plugin release version** (distinct from the internal database schema version).

**Maturity:** Early MVP. Suitable for testing and staged rollouts — not a stable, production-complete, or marketplace-certified release without your own review.

## [Unreleased]

### Added

- **GA stabilization closure (schema 1.16.0)** — Settings UI for global **promotion dry-run**; per-promotion `dry_run` column + edit checkbox + list badge; `PromotionDryRunGuard` (no fees/gifts/line/redemptions); `ScheduleConflictPreviewService` on edit + Diagnostics; `scripts/ga-stress-smoke.php`; `docs/GA_READINESS_DELTA.md`; `tests/Unit/GaClosureTest.php`.

- **GA stabilization phase** — `EcosystemCompatibilityRegistry` matrix + `KnownLimitationsRegistry`; `SystemHealthService` score and recovery recommendations; `PromotionComplexityScorer` and `MerchantSafetyAdvisor`; promotion **dry-run** setting; planner **timing buckets**; request memoization for `find_active_for_planner()`; stale lock cleanup; Diagnostics ecosystem/health/safety panels; `scripts/ga-stabilization-smoke.php`; docs `COMPATIBILITY_MATRIX.md`, `KNOWN_LIMITATIONS.md`, `PERFORMANCE_GUIDE.md`, `MERCHANT_SAFETY.md`, `SCALING_GUIDE.md`, `TROUBLESHOOTING.md`.

### Fixed

- **Line discount cart fatal** — `LinePriceMutationGuard` now imports `Engine\AppliedLineDiscount` (wrong namespace caused fatal on `woocommerce_before_calculate_totals`, breaking all cart recalculation including Blocks Store API backend).
- **Blocks QA setup** — `BlockQaPromotionSetup` provisions distinct cart-addable paid/gift simple products; stackable pair uses `stop_processing=false`; cert pauses all planner-active promos; line cert survives WooCommerce multi-pass totals (`CartPromotionApplier` early subtotal guard).
- **Block checkout order recording** — `woocommerce_store_api_checkout_order_processed` hooks in `WooCommerceBridge` (block COD orders were missing `_mp_cp_applied_promotions` before this fix).
- **`scripts/verify-plugin.sh`** — lifecycle/schema checks always run from live tree; release audit runs from staging build path (skipped with message when only sync copy present).

### Added

- **`scripts/blocks-browser-cert.php`** — WP-CLI + Store API certification harness for block QA promos (dynamic promo IDs, order/reversal checks).
- **Cart/Checkout Blocks browser certification (2026-05-18)** — Final browser pass: fee + coupon COD orders **4362**/**4363**; coupon UI `BLOCKQA239`; CLI cert 8/8. **`cart_checkout_blocks` declared.**

### Changed

- **Cart/Checkout Blocks manual QA (2026-05-18)** — `block_compatibility_status` **passed**; `FeaturesUtil::declare_compatibility( 'cart_checkout_blocks' )`.

- **Cart/Checkout Blocks compatibility investigation** — Draft block QA pages (cart **4333**, checkout **4334**); `scripts/blocks-compatibility-smoke.php`; expanded `docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md` test matrix and hook audit; `BlockTestPages`, `BlockQaPromotionSetup`, optional `BlocksHookAudit` debug logging (`WP_DEBUG` + `mp_cp_blocks_hook_debug`); `CompatibilityStatus` block fields exposed in Reports/Diagnostics and support bundle. **`cart_checkout_blocks` remains undeclared.**
- **Native line discount groundwork (schema 1.15.0)** — `PromotionDiscountApplicationMode` (`fee_based`, `line_item`, `hybrid`); `LineItemDiscountApplier` + `LinePriceMutationGuard` on `woocommerce_before_calculate_totals`; line allocation persistence (`AppliedLineDiscount`, session/order meta); hybrid fee fallback with telemetry; admin **Discount application** field; compatibility audit for line mode. **Fee-based remains the default**; line mode is experimental.
- **Line discount stabilization** — `docs/manual-line-discount-engine-test.md`; admin experimental warnings and Line/Hybrid list badges; Reports **Line discount mode** section; Diagnostics **Repair stuck line discount sessions** (dry-run); persisted fallback telemetry; expanded smoke and PHPUnit coverage.

## [0.2.0-beta.1] - 2026-05-17

First **public beta** for technical pilot users on **classic shortcode** cart and checkout with **WooCommerce HPOS** declared compatible.

### Fixed

- **Checkout recording** — When the cart session payload is empty at `woocommerce_checkout_create_order`, rebuild redemption entries from order fee lines and retry on `woocommerce_checkout_order_processed` (classic COD guest checkout).

### Added

- **Classic browser QA** — Certification on `/cart-2/` + `/checkout-2/` with COD (stacked fees, order recording, reversal); `BETA_RELEASE_DECISION.md`, `CLASSIC_CHECKOUT_CERTIFICATION.md`, `scripts/classic-browser-qa-setup.php`.
- **Browser QA beta release prep** — `BROWSER_QA_RUNBOOK.md`, `CLASSIC_CHECKOUT_CERTIFICATION.md`, `BLOCK_CHECKOUT_INVESTIGATION.md` (draft block QA pages), `RELEASE_EVIDENCE_0.2.0_BETA1.md`, `VERSION_BUMP_PLAN_0.2.0_BETA1.md`, `scripts/beta-release-prep-smoke.php`; local COD enabled for QA documentation.
- **Beta readiness certification** — `docs/BETA_READINESS.md`, `docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md` (blocks not declared); browser QA matrix and evidence updates; real `languages/mp-commerce-promotions.pot` via WP-CLI; CI PHPCS step (continue-on-error); PHPCBF persistence on Service/Admin/Woo target paths; `scripts/beta-readiness-smoke.php`.
- **Production hardening closure** — Reports production hardening dashboard (profiler, safe mode, cron, degraded state, compatibility confidence, slow runs); checkout recording transient lock; cart redemption count memoization; simulation request cache reuse; expanded `scripts/release-audit.sh` (zip + doc checks); `scripts/production-hardening-closure-smoke.php`; PHPUnit `ProductionHardeningClosureTest`; documentation pass for cron/safe/degraded/retention/release-audit.
- **Performance and production hardening** — `PromotionPerformanceProfiler` rolling aggregates; planner prefiltering and `find_active_for_planner()`; concurrency locks (planner, automation, snapshot restore) and warnings; optional WP-Cron hourly/daily hooks (disabled by default); `PromotionDataRetentionService` cleanup and storage estimates; expanded `PricingCompatibilityAnalyzer` confidence scoring; production safety settings (safe mode, telemetry/simulation pause, automation emergency stop, retention days); storefront degraded mode and cart resilience; `AdminPerformanceHardeningPanel` and admin safety banners; `scripts/release-audit.sh` and `scripts/performance-hardening-smoke.php`; `docs/BROWSER_QA_MATRIX.md` and `docs/manual-performance-and-hardening-test.md`; PHPUnit `PerformanceHardeningTest`.
- **Commercial readiness polish** — Getting Started admin tab; expanded Settings (feature gates, data retention/uninstall policy); Compatibility Status on Reports and Diagnostics; support bundle JSON export; `CompatibilityStatus` and `SupportBundleExporter`; opt-in uninstall data deletion via `UninstallDataCleaner`; placeholder `languages/mp-commerce-promotions.pot`; `docs/COMMERCIAL_READINESS.md`; `scripts/commercial-readiness-smoke.php`.
- **Advanced pricing engine groundwork (schema 1.14.0)** — `priority_tier`, `coupon_behavior`, and `allocation_mode` on promotions; `DiscountAllocationEngine` with proportional line/shipping allocation (no line mutation; fee-based storefront unchanged); `TaxAwareDiscountCalculator`, `CouponCoexistenceEvaluator`, and `PricingCompatibilityAnalyzer`; planner tier ordering and coupon skip reasons; allocation explainability and admin preview table; profitability/pricing/shipping analytics and CSV columns; `PromotionBulkPricingWorkflow` and calendar tier/coupon indicators; `PromotionPricingRecovery` diagnostics tools; `AllocationContextCache` metrics; conflict analyzer tier congestion; `scripts/pricing-engine-smoke.php`; `docs/manual-pricing-engine-test.md`.
- **Simulation and forecasting (schema 1.13.0)** — `mp_cp_simulation_scenarios` table; `PromotionSimulationEngine`, `SimulationScenario` / `SimulationResult`, saved scenario repository; `PromotionForecastEngine` and `PromotionReplayEngine` (heuristic, read-only); `PromotionOverlapSimulator` and conflict analyzer overlap mode; `PromotionRecommendationEngine`; `PlannerContextCache` and scope memoization; `PromotionBulkCampaignWorkflow` (schedule, orchestration, label, budget, cooldown); Reports forecasting/calendar/recommendations/intelligence analytics/planner counters/simulation UI; Diagnostics intelligence recovery and recommendations; snapshot intelligence metadata; expanded `PromotionPlanExplainer` and rule validator intelligence warnings; CSV forecast/telemetry columns; `scripts/simulation-forecasting-smoke.php`; `docs/manual-simulation-and-forecasting-test.md`.
- **Automation and observability (schema 1.12.0)** — `mp_cp_automation_runs` and `mp_cp_planner_telemetry` tables; snapshot `snapshot_label` / `snapshot_source`; `PromotionAutomationRunner` (manual `run_all` and lifecycle steps); `PromotionHealthMonitor`; `PlannerTelemetryRecorder` on cart planner; `PromotionOperationalRecovery` (budget recalc, telemetry rebuild, snapshot validate, orchestration repair); Diagnostics automation/health/history/recovery UI; Reports telemetry and health summary; promotion list quick filters and compact mode; duplicate presets (scheduled draft, without budget, orchestration override); `scripts/automation-observability-smoke.php`; `docs/manual-automation-and-observability-test.md`.
- **Orchestration and segmentation (schema 1.11.0)** — `cooldown_hours` and `orchestration_group` on promotions; `mp_cp_promotion_snapshots` rollback table; customer segmentation conditions (`customer_lifetime_spend`, `customer_order_count`, `customer_average_order_value`) with Woo cart metadata enrichment; planner skip reasons `orchestration_group_blocked` and `blocked_by_cooldown`; `PromotionPlanExplainer` plan metrics; `PromotionConflictAnalyzer` orchestration congestion; Reports orchestration metrics and CSV columns; VIP/loyal/returning customer templates; admin orchestration fields, segmentation builder notes, recent snapshots restore; Diagnostics scheduler automation buttons; `PromotionService` activate/archive/normalize automation; `scripts/orchestration-segmentation-smoke.php`; `docs/manual-orchestration-and-segmentation-test.md`.
- **Economics and scheduling (schema 1.10.0)** — promotion `budget_amount` / `budget_spent` / `budget_currency`; code batch `batch_notes` and export tracking (`exported_at`, `exported_by`, `export_count`); `PromotionBudgetLedger` on checkout record/reverse; `PromotionLifecycle` list badges and `lifecycle_phase` filters; `PromotionScheduleAnalyzer` overlap forecasting; Reports date presets and budget economics; Diagnostics “Deactivate exhausted promotions”; admin budget/campaign fields, schedule warnings, batch export audit; `scripts/economics-scheduling-smoke.php`; `docs/manual-economics-and-scheduling-test.md`.
- **Campaign operations (schema 1.9.0)** — `campaign_label`, `internal_notes`, and `admin_color` on promotions; admin Campaign metadata section; list/report filters and color badges; Diagnostics archive hygiene (expired active + old drafts); `PromotionService::archive_expired_active_promotions()` / `archive_old_drafts()`; `scripts/campaign-ops-smoke.php`; `docs/manual-campaign-operations-test.md`.
- **Conflict analysis and planner explainability** — `PromotionConflictAnalyzer` heuristic warnings across active promotions; `PromotionPlanExplainer` summaries on cart preview; conflict table on edit screen; lightweight list indicators (Exclusive, Has exclusions, Conflicts count, Scoped, Stop); `PromotionRuleValidator` conflict heuristic warnings/info; `scripts/conflict-analysis-smoke.php`; `docs/manual-conflict-analysis-test.md`.
- **Promotion templates** — `PromotionTemplate` service with seven admin presets (percent off category, fixed off products, buy X get Y cheapest free, free shipping/gift over subtotal, first order and role discounts); edit screen “Promotion templates” section; `scripts/promotion-template-smoke.php`; `docs/manual-promotion-templates-test.md`.
- **Scoped discount calculations** — `EligibleCartScope` for scoped line subsets/subtotals; conditions `minimum_eligible_subtotal` / `maximum_eligible_subtotal`; scoped `percentage_discount` and `fixed_amount_discount` (fee-based, `calculated_discount` / `applied_discount` previews); cheapest item refactor; `scripts/scoped-discount-smoke.php`; `docs/manual-scoped-discount-test.md`.
- **Product targeting and variation awareness (schema 1.8.0)** — `Woo\CartItemSelector` variation-aware matching; conditions `product_in_cart`, `category_in_cart`, `exclude_sale_items`; promotion-level `excluded_product_ids` / `excluded_category_ids`; `cheapest_item_discount` `variation_ids` and `exclude_sale_items`; admin targeting exclusions + builder fields; trace reason codes `required_product_missing`, `required_category_missing`, `sale_items_present`; `scripts/product-targeting-smoke.php`; `docs/manual-product-targeting-test.md`.
- **Admin UX polish** — `PromotionPicker` dropdown for Reports and exclusion checklist on edit; bulk Activate/Pause/Archive on All Promotions (POST + nonce, no delete); public `PromotionService::is_allowed_status_transition()`; `scripts/admin-ux-smoke.php`.
- **Promotion reports dashboard** — WooCommerce → Promotions **Reports** tab with summary metrics, top promotions table, filters (date range on `redeemed_at`, promotion ID, status), and POST CSV export (5,000 row cap; no raw promotion codes); `PromotionReports` service; `scripts/reports-smoke.php`.
- **Checkout redemption integrity** — `OrderPromotionState` centralizes order meta; idempotent checkout recording and single-pass reversal; restore on `processing`/`completed` after reversal; `FreeGiftCartSynchronizer` removes stale/orphan gifts and normalizes quantities; audit actions `promotion.recorded_on_order`, `promotion.reversed_on_order`, `promotion.gift_added_to_cart`, `promotion.gift_removed_from_cart`; diagnostics integrity notes; `scripts/checkout-integrity-smoke.php`; `docs/manual-checkout-integrity-test.md`.
- **Redemption restrictions and usage limits** — enforce global `usage_limit`, per-customer `customer_usage_limit` (schema **1.7.0**), date window (`promotion_not_started` / `promotion_expired`), and conditions `minimum_cart_quantity` / `maximum_cart_quantity`; planner/admin reason codes; `scripts/redemption-limits-smoke.php`; `docs/manual-redemption-limits-test.md`.
- **`free_gift_product` action** — adds a configured product to the cart when a promotion applies; cart metadata (`mp_cp_free_gift`, promotion id/uuid/name); duplicate prevention; zero price via `woocommerce_before_calculate_totals`; session/order recording with `discount_amount` 0; Simple Rule Builder fields; `scripts/free-gift-smoke.php`; `docs/manual-free-gift-test.md`. Reversal does not remove gift lines from existing orders.
- **Cheapest item discount admin UX** — Simple Rule Builder support; cart preview summaries for `discount_amount` / `not_applicable`; clearer validator messages; `docs/manual-cheapest-item-test.md`.
- **BOGO groundwork: `cheapest_item_discount` action** — `CartItemSelector` for product/category unit targeting; discounts cheapest eligible units (category or products scope) as a negative cart fee; preview includes `discount_amount` / `not_applicable`; `scripts/cheapest-item-smoke.php`. Does not add free products or change line prices.
- **Cart item enrichment** — `unit_price`, `item_key`, and `product_name` on Woo cart context line items when available.
- **`free_shipping` action** — preview `{ "free_shipping": true }`; storefront MVP applies a negative cart fee equal to current shipping total when > 0; dedicated fee labels; `scripts/free-shipping-smoke.php`.
- **`customer_redemption_count` condition** — `QuantityComparator` on metadata; `RedemptionRepository::count_recorded_for_customer()`; logged-in cart enrichment via `CartContextBuilder`.
- **Simple Rule Builder expansion** — `logged_in`, `first_order`, `customer_role`, `billing_country`, `customer_email_domain`, `customer_redemption_count`, and `free_shipping` (no JavaScript).
- **Stacking documentation and smoke** — clarified multi-fee vs exclusive behavior; `docs/manual-stacking-test.md`; cap smoke at natural subtotal (~46).
- **Max applications enforcement** — `PromotionPlanner` enforces plan-level cap (minimum `max_applications` among selected promotions); skipped reason `max_applications_reached`; admin promotion plan table in cart preview.
- **Promotion exclusion rules** — `excluded_promotion_ids` on promotions (schema 1.6.0); planner skips later eligible promotions with `excluded_by_selected_promotion`; admin comma-separated ID field; list column shows exclusion count.
- **Stackable cart fees** — `CartPromotionApplier` applies one negative fee per planner-selected promotion; cumulative discount capped at cart subtotal; session `applied_promotions` array; order meta `_mp_cp_applied_promotions`; multi-promotion redemption recording and reversal.
- **Promotion stacking groundwork** — `application_mode`, `stop_processing`, `max_applications` on promotions; `PromotionPlanner` / `PromotionEvaluationPlan` for multi-promotion selection with skip reasons.
- **WooCommerce HPOS compatibility** — declares `custom_order_tables` via `FeaturesUtil` on `before_woocommerce_init` (`WooCompatibility`); cart/checkout blocks not declared pending block-checkout verification.
- **Evaluation trace / explainability** — `ConditionTrace`, `ActionTrace`, and trace arrays on `EvaluationResult` with stable machine-readable `reason_code` values; admin cart preview shows condition/action trace tables (admin/debug only).
- Conditions **`billing_country`** (ISO codes) and **`customer_email_domain`** (email domain match, case-insensitive).
- Woo cart metadata: `billing_country` and `customer_email` from session customer / user account when available.
- Condition **`customer_role`** (WordPress role slugs in JSON; matches metadata `customer_roles`, case-insensitive).
- Woo cart context enrichment for logged-in users: `has_previous_orders` via `wc_get_orders()` (limit 1), `customer_roles` via user object.
- Conditions **`logged_in`** and **`first_order`**; expanded PHPUnit coverage for evaluator, validator, builder, and domain.
- Release packaging workflow (`scripts/build-zip.sh`, `docs/RELEASE_CHECKLIST.md`, this changelog).

### Beta release notes (caveats)

- **Audience:** Technical pilot stores on **classic shortcode** cart/checkout — not a general-availability production release.
- **HPOS:** Declared compatible (`custom_order_tables`). **Cart/Checkout Blocks:** **Not** declared; use classic pages or accept unverified block behavior.
- **Browser QA:** Stacked checkout, COD order placement, recording, and reversal **passed**; scoped %, free gift line, free shipping with paid shipping, budget/cooldown, and CSV export are **partial** or **not run** (see `docs/CLASSIC_CHECKOUT_CERTIFICATION.md`).
- **Discount model:** Negative cart fees (and free gift lines); not native line-item or coupon discounts.
- **PHPCS:** Non-gating in CI (`continue-on-error`); baseline not clean.
- **Schema:** Database schema remains **1.14.0** (no migration in this release).

## [0.1.0] - 2026-05-16

### Added

- **Promotion engine** — non-persistent evaluation pipeline (`PromotionEvaluator`, `EvaluationContext`, `EvaluationResult`) with `RuleTypes` / `RuleRegistry` for supported condition and action identifiers.
- **Conditions** — `minimum_subtotal`, `product_quantity`, `category_quantity`.
- **Actions** — `percentage_discount`, `fixed_amount_discount` (preview at evaluation time).
- **WooCommerce cart integration** — first eligible active promotion or code-linked promotion applied as a **negative cart fee**; settings kill switch for cart discount application.
- **Promotion codes** — hashed storage (SHA-256 + last four characters); virtual coupon bridge with zero native coupon discount.
- **Batch code generation** — up to 1,000 codes per batch; show-once copy/CSV in admin.
- **Redemptions** — idempotent per order/promotion; reversal on cancel, fail, refund, trash/delete.
- **Admin UI** — promotions list (search, filters, pagination), edit workflow, Simple Rule Builder, raw JSON rules, validation panel, duplicate-as-draft, cart preview, settings and diagnostics tabs.
- **Diagnostics / repair** — usage counter scans (capped) and manual repair actions.
- **Data layer** — custom tables, migrations (`Schema`, `MigrationRunner`), audit log, domain repositories and services.
- **Marketplace scaffolding** — `readme.txt`, `LICENSE`, i18n bootstrap, architecture and development docs, manual test guides.

### Notes

- Runtime autoload via `src/autoload.php` (Composer is dev-tooling only).
- Database schema version is tracked separately (`mp_cp_schema_version`; see `Schema::SCHEMA_VERSION` in code).
- PHPCS baseline is not clean; automated tests and CI are not yet in place.

[Unreleased]: https://github.com/magpern/mp-commerce-promotions/compare/v0.2.0-beta.1...HEAD
[0.2.0-beta.1]: https://github.com/magpern/mp-commerce-promotions/compare/v0.1.0...v0.2.0-beta.1
[0.1.0]: https://github.com/magpern/mp-commerce-promotions/releases/tag/v0.1.0
