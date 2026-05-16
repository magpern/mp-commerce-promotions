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

**Early development / scaffold phase.** Storefront **v1** applies at most **one** eligible **active** promotion as a **negative WooCommerce cart fee** (**`percentage_discount`** or **`fixed_amount_discount`**; fixed amount is clamped to cart subtotal). **Redemption** is **idempotent per `(order_id, promotion_id)`** (no duplicate rows or double **`usage_count`** / **`promotion.redeemed`** audit); **`_mp_cp_redemption_recorded`** meta marks a successful write; **`mp_cp_applied_promotion`** session is cleared after recording. **Reversal** runs when an order moves to **cancelled**, **failed**, or **refunded** (via **`woocommerce_order_status_cancelled`**, **`woocommerce_order_status_failed`**, **`woocommerce_order_status_refunded`**), or when the order is **trashed** or **deleted** (WooCommerce **`woocommerce_before_trash_order`** / **`woocommerce_before_delete_order`**, plus CPT **`before_trash_post`** / **`before_delete_post`** for **`shop_order`** when **`wc_get_order`** resolves). It marks the redemption row **`reversed`**, decrements **`usage_count`** by **at most one** (never below 0), sets **`_mp_cp_redemption_reversed`** = **`yes`**, and logs **`promotion.redemption_reversed`** once (**idempotent**; **partial refunds** are **not** supported). **Manual promotion codes** can be entered in the standard **WooCommerce coupon field** (no native `shop_coupon` posts are created; codes are matched by **SHA-256 hash**). When a matching **usable** code is applied, only its linked promotion runs (not automatic promotions). **Code `usage_count`** increments once on the first successful order recording and decrements **at most once** on reversal (never below 0), idempotent via **`_mp_cp_redemption_reversed`**. **BOGO**, **multi-promotion stacking**, and **native WC coupon discounts** for our codes are **not** implemented (virtual coupons use **0** discount; the fee comes from this plugin). **Cart discounts** can be turned off under **WooCommerce → Promotion Settings** (option **`mp_cp_cart_discounts_enabled`**). Developers can still override with: `add_filter( 'mp_cp_enable_cart_discounts', '__return_false' );`

## Requirements

- WordPress 6.4+
- PHP 7.4+
- WooCommerce (recommended for admin integration and future features)

## Database

- **Schema version (option):** `mp_cp_schema_version` — current target is **`1.2.0`** (see `Schema::SCHEMA_VERSION`).
- **Tables** (after activation / migration), using the site table prefix (e.g. `wp_`):
  - `{prefix}mp_cp_promotions` — promotion definitions (JSON-like rules in `LONGTEXT` columns).
  - `{prefix}mp_cp_redemptions` — usage against orders; **unique** `(order_id, promotion_id)` as **`order_promotion_unique`** (MySQL allows multiple `NULL` `order_id` rows; real checkouts use non-null `order_id`). Migration to **1.1.0** **refuses** `dbDelta` / version bump if duplicate non-null `(order_id, promotion_id)` pairs already exist (see `MigrationRunner`).
  - `{prefix}mp_cp_audit_log` — append-only audit trail.
  - `{prefix}mp_cp_promotion_codes` — manual promotion codes (hashed; **unique** `code_hash`). Plain codes are **never** stored; admin UI shows only **`code_last4`** after creation.
- **Deactivation:** tables and `mp_cp_schema_version` are **not** removed; migrations are **additive** and intended to be **rollback-safe** (no `DROP TABLE` / data deletion in core flows).
- **Migrations:** `MigrationRunner` runs `dbDelta()` from Schema DDL on activation when the stored version is behind `Schema::SCHEMA_VERSION`. **1.1.0** adds the redemptions unique guard; if duplicates exist, the option is **not** advanced until data is fixed (see `MigrationRunner` / `WP_DEBUG` log).

## Domain and persistence

- **`Promotion`** / **`PromotionStatus`** / **`PromotionFactory`** — validated domain model; `conditions`, `actions`, and `restrictions` are PHP arrays in memory.
- **`PromotionRepository`** — reads/writes the `{prefix}mp_cp_promotions` table using `$wpdb` (`insert`, `update`, `find`, `find_by_uuid`, `find_by_id_or_uuid`, `find_active`, `delete`, `find_all`, `count_all`). JSON columns are stored as **LONGTEXT** via `wp_json_encode()`; loads decode defensively (invalid JSON becomes an empty array). No public REST layer; admin list is read-only for **editing** rows (creation uses `PromotionService`).
- **`Redemption`** / **`RedemptionRepository`** — **`{prefix}mp_cp_redemptions`** rows (**`insert`**, **`update`** status only, **`find_for_order`**, **`find_for_promotion`**, **`find_recorded_for_order_and_promotion`**, **`exists_for_order_and_promotion`**, **`count_for_order`**); statuses **`recorded`** / **`reversed`**; **no delete API**. From schema **1.1.0**, the table has a **unique** index **`order_promotion_unique`** on **`(order_id, promotion_id)`** (duplicate inserts for the same non-null pair fail at the database).
- **`AuditLogEntry`** / **`AuditLogRepository`** — append-only writes to `{prefix}mp_cp_audit_log`; **raw IP addresses are never stored** (only a SHA-256 hash of a validated `REMOTE_ADDR` when present).
- **`AuditLogger`** / **`PromotionService`** — internal orchestration: `PromotionService::create_draft()` persists a draft and records `promotion.created`; `update_promotion()` records `promotion.updated`; `change_status()` applies allowed lifecycle transitions and records `promotion.status_changed`.
- **`PromotionCode`** / **`PromotionCodeFactory`** / **`PromotionCodeRepository`** — manual codes for a promotion (`active` / `disabled` / `expired`); `hash('sha256', strtoupper(trim($plain)))` for lookup; **`is_code_usable`**, **`insert`**, **`find`**, **`update`**, **`find_by_plain_code`**, **`find_for_promotion`**, **`increment_usage`** (no delete). Storefront: customers enter codes in the **WooCommerce coupon field**; **`PromotionCodeCouponBridge`** supplies virtual coupon data and validates via **`woocommerce_get_shop_coupon_data`** / **`woocommerce_coupon_is_valid`**. **`CartPromotionApplier`** applies the linked promotion fee when a matching code is on the cart; otherwise **automatic** first eligible active promotion still applies. Order meta: **`_mp_cp_promotion_code_id`**, **`_mp_cp_promotion_code_last4`**. **No batch generation** yet.

## Evaluation pipeline

- **`EvaluationContext`** — generic inputs (`customer_id`, `cart_subtotal`, `currency`, `items`, `metadata`); no WooCommerce cart objects.
- **`CartContextBuilder`** (WooCommerce) — read-only mapping from `WC()->cart` into **`EvaluationContext`** (product line summaries + `product_cat` term IDs); returns an empty context when WooCommerce or the cart is unavailable.
- **Conditions** — implement `ConditionInterface::evaluate()` and return **`ConditionResult`** (pass/fail with optional message).
- **Actions** — implement `ActionInterface::preview()` and return **`ActionResult`** (type + payload). The evaluator uses previews only; **`CartPromotionApplier`** turns the first eligible **`percentage_discount`** or **`fixed_amount_discount`** into a cart fee on the storefront.
- **`PromotionEvaluator::evaluate()`** — loads rule objects from a **`Promotion`**’s `conditions` / `actions` arrays only; **no database, WooCommerce, or audit calls** in this phase.
- **Demo types supported:** conditions **`minimum_subtotal`**, **`product_quantity`** (sum line qty for `product_id`), **`category_quantity`** (sum line qty for items in `category_id`); actions **`percentage_discount`** and **`fixed_amount_discount`** (v1 fee sources). Only the **first eligible promotion** applies; within its actions, the **first** supported discount action wins. **Fixed amount** discounts are clamped to **> 0** and **≤ cart subtotal**.
- **Admin cart preview** — **Edit promotion** can run **`PromotionEvaluator`** against the current session cart via **`CartContextBuilder`**; **admin-only**, **no persistence**, **does not add cart fees**.
- **Storefront cart fees (v1)** — **`CartPromotionApplier`** runs on **`woocommerce_cart_calculate_fees`** (priority **20**): loads **`find_active()`** promotions in priority order, evaluates with **`CartContextBuilder`**, applies the **first** eligible promotion’s **first** **`percentage_discount`** (subtotal × percentage / 100, clamped to subtotal) or **`fixed_amount_discount`** (configured amount, clamped to subtotal) as **`WC()->cart->add_fee( $label, -$discount, false )`**. When a fee applies, the choice is mirrored into **`WC()->session`** as **`mp_cp_applied_promotion`** (cart-only; cleared when no promotion applies). **`OrderPromotionRecorder`** listens to **`woocommerce_checkout_create_order`** (priority **10**): validates session payload, writes **`_mp_cp_*`** meta plus **`_mp_cp_redemption_recorded`** = **`yes`** on first successful persist, inserts one **redemption** per **`(order_id, promotion_id)`** (duplicate hook runs only refresh meta and clear session), increments **`usage_count`** and logs **`promotion.redeemed`** only on that first insert, then clears **`mp_cp_applied_promotion`**. On **cancelled**, **failed**, **refunded**, **trash**, or **delete**, **`reverse_for_order()`** reverses a **recorded** redemption once (**`_mp_cp_redemption_reversed`**), decrements promotion and linked **code** **`usage_count`** by **at most one** each, and logs **`promotion.redemption_reversed`**. **Partial refunds** are not handled yet; **coupons** and **multi-promotion stacking** are out of scope for v1. Toggle cart fee math with **`mp_cp_enable_cart_discounts`** (default `true`).

## Admin UI

Admin navigation (under **WooCommerce**):

```text
WooCommerce
├── Promotions
├── Promotion Settings
└── Promotion Diagnostics
```

List, settings, and diagnostics screens share **in-page nav tabs** (**All Promotions** | **Settings** | **Diagnostics**) using WordPress `nav-tab` styling (no JavaScript).

- **Promotions** (`admin.php?page=mp-commerce-promotions`) lists promotions (status column is read-only), supports **Create draft promotion** (name + nonce), and **Edit** opens a detail page (`promotion` query arg: numeric id or UUID). The **All Promotions** tab is active on this screen.
- **Promotion Settings** (`admin.php?page=mp-commerce-promotions-settings`) provides an **Enable cart discounts** checkbox (stored as **`mp_cp_cart_discounts_enabled`**, default **yes**). The **Settings** tab is active on this screen.
- **Promotion Diagnostics** (`admin.php?page=mp-commerce-promotions-diagnostics`) uses **`UsageDiagnostics`** to compare stored **`usage_count`** values on promotions and codes against computed counts from **recorded** / **reversed** redemptions (and order meta **`_mp_cp_promotion_code_id`** for codes). Mismatches are highlighted. Admins can run **Repair Usage Counters** (POST + nonce + confirm): **`repair()`** recalculates mismatched counters from recorded redemptions; **no rows are deleted**; repairs are **audited** (`promotion.usage_repaired`, `promotion_code.usage_repaired`). There is **no automatic or scheduled repair**. The **Diagnostics** tab is active on this screen.
- **Status** is changed only via **controlled POST actions** (Activate / Pause / Archive); **archived** promotions **cannot be reactivated**. The main form edits name, description, priority, dates, and **raw JSON** for conditions, actions, and restrictions (validated as JSON arrays). The edit screen includes a read-only **Rule Validation** panel (**`PromotionRuleValidator`**) that checks stored JSON against supported condition/action types (unknown types, missing fields, no actions, multiple actions, status hints). It does **not** guarantee cart eligibility. **Promotion Codes** on the edit screen let admins create **manual** codes (hashed at rest; list shows **last 4** only). **Storefront code entry / redemption** is **not** implemented yet. **Preview against current cart** runs **`PromotionEvaluator`** in-memory using **`CartContextBuilder`** (no DB writes; **does not** add storefront fees). The promotion detail page also shows **read-only** **Usage / Redemptions** (latest 25 rows from **`RedemptionRepository::find_for_promotion`**) and **Audit Log** (latest 25 from **`AuditLogRepository::find_for_promotion`**, context as pretty JSON). **Hard delete UI**, **visual rule builder**, and **REST/AJAX** are **not** implemented yet.

## Install (development)

Copy this folder into `wp-content/plugins/`, then activate **MP Commerce Promotions** in the WordPress admin.

## Manual testing

For a full storefront walkthrough (cart fee, order meta, redemption, idempotency, reversal), use **[docs/manual-checkout-test.md](docs/manual-checkout-test.md)**.
