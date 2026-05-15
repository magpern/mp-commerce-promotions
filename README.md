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

**Early development / scaffold phase.** Storefront **v1** applies at most **one** eligible **active** promotion as a **negative WooCommerce cart fee** (percentage of cart subtotal only). **Basic redemption** records rows in **`{prefix}mp_cp_redemptions`**, **order meta** (`_mp_cp_*`), **`promotion.usage_count`**, and audit **`promotion.redeemed`** on checkout (session key **`mp_cp_applied_promotion`**). **Refund/reversal**, **coupons**, **BOGO**, and **multi-promotion stacking** are **not** implemented. Disable cart fees globally with: `add_filter( 'mp_cp_enable_cart_discounts', '__return_false' );`

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
- **`PromotionRepository`** — reads/writes the `{prefix}mp_cp_promotions` table using `$wpdb` (`insert`, `update`, `find`, `find_by_uuid`, `find_by_id_or_uuid`, `find_active`, `delete`, `find_all`, `count_all`). JSON columns are stored as **LONGTEXT** via `wp_json_encode()`; loads decode defensively (invalid JSON becomes an empty array). No public REST layer; admin list is read-only for **editing** rows (creation uses `PromotionService`).
- **`Redemption`** / **`RedemptionRepository`** — append-only **`{prefix}mp_cp_redemptions`** rows (`insert`, `find_for_order`, `find_for_promotion`); **no delete API**.
- **`AuditLogEntry`** / **`AuditLogRepository`** — append-only writes to `{prefix}mp_cp_audit_log`; **raw IP addresses are never stored** (only a SHA-256 hash of a validated `REMOTE_ADDR` when present).
- **`AuditLogger`** / **`PromotionService`** — internal orchestration: `PromotionService::create_draft()` persists a draft and records `promotion.created`; `update_promotion()` records `promotion.updated`; `change_status()` applies allowed lifecycle transitions and records `promotion.status_changed`.

## Evaluation pipeline

- **`EvaluationContext`** — generic inputs (`customer_id`, `cart_subtotal`, `currency`, `items`, `metadata`); no WooCommerce cart objects.
- **`CartContextBuilder`** (WooCommerce) — read-only mapping from `WC()->cart` into **`EvaluationContext`** (product line summaries + `product_cat` term IDs); returns an empty context when WooCommerce or the cart is unavailable.
- **Conditions** — implement `ConditionInterface::evaluate()` and return **`ConditionResult`** (pass/fail with optional message).
- **Actions** — implement `ActionInterface::preview()` and return **`ActionResult`** (type + payload). The evaluator uses previews only; **`CartPromotionApplier`** turns the first eligible **`percentage_discount`** into a cart fee on the storefront.
- **`PromotionEvaluator::evaluate()`** — loads rule objects from a **`Promotion`**’s `conditions` / `actions` arrays only; **no database, WooCommerce, or audit calls** in this phase.
- **Demo types supported:** `minimum_subtotal` (condition) and `percentage_discount` (action preview / v1 fee source).
- **Admin cart preview** — **Edit promotion** can run **`PromotionEvaluator`** against the current session cart via **`CartContextBuilder`**; **admin-only**, **no persistence**, **does not add cart fees**.
- **Storefront cart fees (v1)** — **`CartPromotionApplier`** runs on **`woocommerce_cart_calculate_fees`** (priority **20**): loads **`find_active()`** promotions in priority order, evaluates with **`CartContextBuilder`**, applies the **first** eligible promotion’s **first** **`percentage_discount`** as **`WC()->cart->add_fee( $label, -$discount, false )`** (subtotal × percentage / 100, clamped to subtotal). When a fee applies, the choice is mirrored into **`WC()->session`** as **`mp_cp_applied_promotion`** (cart-only; cleared when no promotion applies). **`OrderPromotionRecorder`** listens to **`woocommerce_checkout_create_order`** (priority **10**): validates session payload, writes **`_mp_cp_promotion_id`**, **`_mp_cp_promotion_uuid`**, **`_mp_cp_promotion_name`**, **`_mp_cp_discount_amount`**, **`_mp_cp_action_type`**, **`_mp_cp_percentage`** on the order (CRUD / HPOS-safe), inserts a **redemption** row (`status` **`recorded`**), increments **`promotion.usage_count`**, and logs **`promotion.redeemed`** (no session clear in recorder yet). **No refund/reversal**, **no coupons**, **no stacking** beyond the single winner. Toggle cart fee math with **`mp_cp_enable_cart_discounts`** (default `true`).

## Admin UI

- **WooCommerce → Promotions** lists promotions (status column is read-only), supports **Create draft promotion** (name + nonce), and **Edit** opens a detail page (`promotion` query arg: numeric id or UUID). **Status** is changed only via **controlled POST actions** (Activate / Pause / Archive); **archived** promotions **cannot be reactivated**. The main form edits name, description, priority, dates, and **raw JSON** for conditions, actions, and restrictions (validated as JSON arrays). **Preview against current cart** runs **`PromotionEvaluator`** in-memory using **`CartContextBuilder`** (no DB writes; **does not** add storefront fees). **Hard delete UI**, **visual rule builder**, and **REST/AJAX** are **not** implemented yet.

## Install (development)

Copy this folder into `wp-content/plugins/`, then activate **MP Commerce Promotions** in the WordPress admin.
