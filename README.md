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

**Early development / scaffold phase.** Storefront **v1** applies at most **one** eligible **active** promotion as a **negative WooCommerce cart fee** (percentage of cart subtotal only). **Redemption** is **idempotent per `(order_id, promotion_id)`** (no duplicate rows or double **`usage_count`** / **`promotion.redeemed`** audit); **`_mp_cp_redemption_recorded`** meta marks a successful write; **`mp_cp_applied_promotion`** session is cleared after recording. **Reversal** runs when an order moves to **cancelled**, **failed**, or **refunded** (via **`woocommerce_order_status_cancelled`**, **`woocommerce_order_status_failed`**, **`woocommerce_order_status_refunded`**), or when the order is **trashed** or **deleted** (WooCommerce **`woocommerce_before_trash_order`** / **`woocommerce_before_delete_order`**, plus CPT **`before_trash_post`** / **`before_delete_post`** for **`shop_order`** when **`wc_get_order`** resolves). It marks the redemption row **`reversed`**, decrements **`usage_count`** by **at most one** (never below 0), sets **`_mp_cp_redemption_reversed`** = **`yes`**, and logs **`promotion.redemption_reversed`** once (**idempotent**; **partial refunds** are **not** supported). **Coupons**, **BOGO**, and **multi-promotion stacking** are **not** implemented. Disable cart fees globally with: `add_filter( 'mp_cp_enable_cart_discounts', '__return_false' );`

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
- **`Redemption`** / **`RedemptionRepository`** — **`{prefix}mp_cp_redemptions`** rows (**`insert`**, **`update`** status only, **`find_for_order`**, **`find_for_promotion`**, **`find_recorded_for_order_and_promotion`**, **`exists_for_order_and_promotion`**, **`count_for_order`**); statuses **`recorded`** / **`reversed`**; **no delete API**.
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
- **Storefront cart fees (v1)** — **`CartPromotionApplier`** runs on **`woocommerce_cart_calculate_fees`** (priority **20**): loads **`find_active()`** promotions in priority order, evaluates with **`CartContextBuilder`**, applies the **first** eligible promotion’s **first** **`percentage_discount`** as **`WC()->cart->add_fee( $label, -$discount, false )`** (subtotal × percentage / 100, clamped to subtotal). When a fee applies, the choice is mirrored into **`WC()->session`** as **`mp_cp_applied_promotion`** (cart-only; cleared when no promotion applies). **`OrderPromotionRecorder`** listens to **`woocommerce_checkout_create_order`** (priority **10**): validates session payload, writes **`_mp_cp_*`** meta plus **`_mp_cp_redemption_recorded`** = **`yes`** on first successful persist, inserts one **redemption** per **`(order_id, promotion_id)`** (duplicate hook runs only refresh meta and clear session), increments **`usage_count`** and logs **`promotion.redeemed`** only on that first insert, then clears **`mp_cp_applied_promotion`**. On **cancelled**, **failed**, **refunded**, **trash**, or **delete**, **`reverse_for_order()`** reverses a **recorded** redemption once (**`_mp_cp_redemption_reversed`**), decrements **`usage_count`** once, and logs **`promotion.redemption_reversed`**. **Partial refunds** are not handled yet; **coupons** and **multi-promotion stacking** are out of scope for v1. Toggle cart fee math with **`mp_cp_enable_cart_discounts`** (default `true`).

## Admin UI

- **WooCommerce → Promotions** lists promotions (status column is read-only), supports **Create draft promotion** (name + nonce), and **Edit** opens a detail page (`promotion` query arg: numeric id or UUID). **Status** is changed only via **controlled POST actions** (Activate / Pause / Archive); **archived** promotions **cannot be reactivated**. The main form edits name, description, priority, dates, and **raw JSON** for conditions, actions, and restrictions (validated as JSON arrays). **Preview against current cart** runs **`PromotionEvaluator`** in-memory using **`CartContextBuilder`** (no DB writes; **does not** add storefront fees). **Hard delete UI**, **visual rule builder**, and **REST/AJAX** are **not** implemented yet.

## Install (development)

Copy this folder into `wp-content/plugins/`, then activate **MP Commerce Promotions** in the WordPress admin.
