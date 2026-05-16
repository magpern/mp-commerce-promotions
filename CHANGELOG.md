# Changelog

All notable changes to **Commerce Promotions for WooCommerce** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) for the **plugin release version** (distinct from the internal database schema version).

**Maturity:** Early MVP. Suitable for testing and staged rollouts — not a stable, production-complete, or marketplace-certified release without your own review.

## [Unreleased]

### Added

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
