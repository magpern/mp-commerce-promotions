# Commerce Promotions for WooCommerce

A **lightweight promotion engine** for WooCommerce. This repository is intended as a **generic WooCommerce extension**, not a store-specific plugin.

**Repository:** [https://github.com/magpern/mp-commerce-promotions](https://github.com/magpern/mp-commerce-promotions)

## Purpose

Provide a structured foundation for commerce promotions using:

- **Conditions** — when a promotion may apply  
- **Actions** — what the promotion does  
- **Restrictions** — limits and guardrails  
- **Evaluation pipeline** — how rules are resolved at runtime (planned)

## Status

**Early development / scaffold phase.** The codebase is **not production-feature-complete** yet (no full promotion evaluation, cart wiring, or data persistence in the current release).

## Requirements

- WordPress 6.4+
- PHP 7.4+
- WooCommerce (recommended for admin integration and future features)

## Database

- **Schema version (option):** `mp_cp_schema_version` — current target is **`1.0.0`** (see `Schema::SCHEMA_VERSION`).
- **Tables** (after activation / migration), using the site table prefix (e.g. `wp_`):
  - `{prefix}mp_cp_promotions` — promotion definitions (JSON-like rules in `LONGTEXT` columns).
  - `{prefix}mp_cp_redemptions` — usage against orders.
  - `{prefix}mp_cp_audit_log` — append-only audit trail.
- **Deactivation:** tables and `mp_cp_schema_version` are **not** removed; migrations are **additive** and intended to be **rollback-safe** (no `DROP TABLE` / data deletion in core flows).
- **Migrations:** `MigrationRunner` runs `dbDelta()` from Schema DDL on activation when the stored version is behind `Schema::SCHEMA_VERSION`.

## Domain and persistence

- **`Promotion`** / **`PromotionStatus`** / **`PromotionFactory`** — validated domain model; `conditions`, `actions`, and `restrictions` are PHP arrays in memory.
- **`PromotionRepository`** — reads/writes the `{prefix}mp_cp_promotions` table using `$wpdb` (`insert`, `update`, `find`, `find_by_uuid`, `delete`). JSON columns are stored as **LONGTEXT** via `wp_json_encode()`; loads decode defensively (invalid JSON becomes an empty array). This layer is **internal** only (no admin UI or REST exposure yet).

## Install (development)

Copy this folder into `wp-content/plugins/`, then activate **MP Commerce Promotions** in the WordPress admin.
