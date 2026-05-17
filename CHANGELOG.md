# Changelog

All notable changes to **Commerce Promotions for WooCommerce** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) for the **plugin release version** (distinct from the internal database schema version).

**Maturity:** Early MVP. Suitable for testing and staged rollouts — not a stable, production-complete, or marketplace-certified release without your own review.

## [Unreleased]

### Added

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

[Unreleased]: https://github.com/magpern/mp-commerce-promotions/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/magpern/mp-commerce-promotions/releases/tag/v0.1.0
