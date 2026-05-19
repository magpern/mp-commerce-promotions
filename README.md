# Commerce Promotions for WooCommerce

A **lightweight promotion engine** for WooCommerce. This repository is intended as a **generic WooCommerce extension**, not a store-specific plugin.

**Repository:** [https://github.com/magpern/mp-commerce-promotions](https://github.com/magpern/mp-commerce-promotions)

**Maturity:** Early MVP / active development. Suitable for testing and staged rollouts; not positioned as marketplace-ready or production-complete without your own review.

## Documentation

- [CHANGELOG.md](CHANGELOG.md) — release history (Keep a Changelog)
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — system design, layers, security, and roadmap
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — Composer lint/test tooling, PHPCS incremental cleanup, admin helpers, Docker/WP-CLI verification
- [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md) — version bump, tag, zip, sync, and manual test steps
- [docs/TASKS.md](docs/TASKS.md) — milestones, backlog, and out-of-scope items
- [docs/manual-checkout-test.md](docs/manual-checkout-test.md) — storefront checkout and redemption walkthrough
- [docs/manual-checkout-integrity-test.md](docs/manual-checkout-integrity-test.md) — idempotency, reversal, free gift sync
- [docs/manual-stacking-test.md](docs/manual-stacking-test.md) — stackable fees, caps, exclusions, and max applications
- [docs/manual-conflict-analysis-test.md](docs/manual-conflict-analysis-test.md) — conflict heuristics, planner explainability, admin debugging workflow
- [docs/manual-campaign-operations-test.md](docs/manual-campaign-operations-test.md) — campaign labels, colors, notes, archive hygiene
- [docs/manual-economics-and-scheduling-test.md](docs/manual-economics-and-scheduling-test.md) — budgets, lifecycle phases, schedule warnings, reports economics
- [docs/manual-cheapest-item-test.md](docs/manual-cheapest-item-test.md) — cheapest item / BOGO fee-offset storefront verification
- [docs/manual-free-gift-test.md](docs/manual-free-gift-test.md) — free gift product cart line storefront verification
- [docs/manual-redemption-limits-test.md](docs/manual-redemption-limits-test.md) — usage limits, per-customer caps, dates, cart quantity conditions
- [docs/manual-promotion-code-test.md](docs/manual-promotion-code-test.md) — promotion codes and coupon-field behavior
- [docs/MANUAL_QA_EVIDENCE.md](docs/MANUAL_QA_EVIDENCE.md) — manual/browser QA evidence (latest verification bundle)
- [docs/GIFT_CARD_QA_EVIDENCE.md](docs/GIFT_CARD_QA_EVIDENCE.md) — gift card & store credit storefront QA (CLI + manual notes)
- [docs/GIFT_CARD_EMAILS.md](docs/GIFT_CARD_EMAILS.md) — delivery templates, WooCommerce email style, settings preview/test, sender modes (default vs custom), SMTP testing
- [docs/GIFT_CARD_PRODUCTS.md](docs/GIFT_CARD_PRODUCTS.md) — gift card product meta (`product_price`, `fixed_amount`, `customer_amount`) + `gift-card-product-setup.php` QA product + `gift-card-customer-amount-smoke.php`
- [docs/QA_SCRIPT_SAFETY.md](docs/QA_SCRIPT_SAFETY.md) — production-safe defaults for WP-CLI smoke/QA scripts (env vars, cleanup, email suppression)
- [docs/GIFT_CARD_PILOT_CHECKLIST.md](docs/GIFT_CARD_PILOT_CHECKLIST.md) — pilot go-live checklist (SMTP, tests, diagnostics)
- [docs/manual-performance-and-hardening-test.md](docs/manual-performance-and-hardening-test.md) — profiler, safe mode, cron, cleanup, concurrency
- [docs/BROWSER_QA_MATRIX.md](docs/BROWSER_QA_MATRIX.md) — reproducible browser QA matrix
- [docs/BETA_READINESS.md](docs/BETA_READINESS.md) — beta certification status and release criteria
- [docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md) — blocks compatibility (declared 2026-05-18)
- [docs/BROWSER_QA_RUNBOOK.md](docs/BROWSER_QA_RUNBOOK.md) — browser QA setup (gateways, products, promotions)
- [docs/CLASSIC_CHECKOUT_CERTIFICATION.md](docs/CLASSIC_CHECKOUT_CERTIFICATION.md) — classic checkout pass/fail matrix
- [docs/BLOCK_CHECKOUT_INVESTIGATION.md](docs/BLOCK_CHECKOUT_INVESTIGATION.md) — block cart/checkout test pages
- [docs/RELEASE_EVIDENCE_0.2.0_BETA1.md](docs/RELEASE_EVIDENCE_0.2.0_BETA1.md) — beta release evidence bundle
- [docs/VERSION_BUMP_PLAN_0.2.0_BETA1.md](docs/VERSION_BUMP_PLAN_0.2.0_BETA1.md) — version bump checklist (pre-tag)
- [docs/BETA_RELEASE_DECISION.md](docs/BETA_RELEASE_DECISION.md) — classic QA outcome and beta recommendation

WordPress.org-style [readme.txt](readme.txt) and [LICENSE](LICENSE) are included as distribution scaffolding.

## Purpose

Provide a structured foundation for commerce promotions using:

- **Conditions** — when a promotion may apply  
- **Actions** — what the promotion does  
- **Restrictions** — limits and guardrails  
- **Evaluation pipeline** — how rules are resolved at runtime (planned)

## Manual QA status (2026-05-17)

- **Evidence doc:** [docs/MANUAL_QA_EVIDENCE.md](docs/MANUAL_QA_EVIDENCE.md)
- **Browser/admin:** Commerce Growth admin (Campaign Builder, Advanced Promotions, tabs), edit screen, and storefront cart **partially** verified on https://www.biopentra.eu (logged-in admin; BTCPay blocks full checkout).
- **WP-CLI smokes:** 8/9 scripts pass; `stacking-smoke.php` order-row assertions fail while `checkout-integrity-smoke.php` passes stacked recording.
- **Unit tests:** 197 tests, 407 assertions.
- **Known blockers:** BTCPay-only payment, CLI cart vs browser session, variable-product gift cart noise, admin session overlay during bulk/export automation.

## Status

**MVP / early development.** Storefront **v1** applies **negative WooCommerce cart fees** per planner-selected promotion (**`percentage_discount`**, **`fixed_amount_discount`**, **`free_shipping`** fee-offset, or **`cheapest_item_discount`** BOGO-style unit targeting) and can **add free gift cart lines** via **`free_gift_product`** (zero line price, no fee); **one action per promotion**; **cumulative cart discount capped at paid cart subtotal** (gift lines excluded); free shipping and free gifts do not consume that cap). **Exclusive** promotions (default) still allow only one selected promotion in the plan; **stackable** promotions with **stop processing** off can apply **multiple fees**. **Code-linked** promotions (coupon field) do **not** stack with automatic promotions. **Redemption** is **idempotent per `(order_id, promotion_id)`** (no duplicate rows or double **`usage_count`** / **`promotion.redeemed`** audit); **`_mp_cp_redemption_recorded`** meta marks a successful write; **`mp_cp_applied_promotion`** session is cleared after recording. **Reversal** runs when an order moves to **cancelled**, **failed**, or **refunded** (via **`woocommerce_order_status_cancelled`**, **`woocommerce_order_status_failed`**, **`woocommerce_order_status_refunded`**), or when the order is **trashed** or **deleted** (WooCommerce **`woocommerce_before_trash_order`** / **`woocommerce_before_delete_order`**, plus CPT **`before_trash_post`** / **`before_delete_post`** for **`shop_order`** when **`wc_get_order`** resolves). It marks the redemption row **`reversed`**, decrements **`usage_count`** by **at most one** (never below 0), sets **`_mp_cp_redemption_reversed`** = **`yes`**, and logs **`promotion.redemption_reversed`** once (**idempotent**; **partial refunds** are **not** supported). **Manual promotion codes** can be entered in the standard **WooCommerce coupon field** (no native `shop_coupon` posts are created; codes are matched by **SHA-256 hash**). When a matching **usable** code is applied, only its linked promotion runs (not automatic promotions). **Code `usage_count`** increments once on the first successful order recording and decrements **at most once** on reversal (never below 0), idempotent via **`_mp_cp_redemption_reversed`**. **BOGO**, **line-item discounts**, and **native WC coupon discounts** for our codes are **not** implemented (virtual coupons use **0** discount; the fee comes from this plugin). **Cart discounts** can be turned off under **WooCommerce → Commerce Growth → Settings** (option **`mp_cp_cart_discounts_enabled`**) or via `add_filter( 'mp_cp_enable_cart_discounts', '__return_false' );`

## Requirements

- WordPress 6.5+
- PHP 8.1+
- WooCommerce 8.0+ (required for cart integration, codes, and admin UI)

## Commercial readiness (beta)

- **Commerce Growth** admin shell — WooCommerce submenu label; slug remains `mp-commerce-promotions` for backward-compatible URLs.
- **Campaign Builder** (`?page=mp-commerce-promotions` or `tab=campaign-builder`) — **default entrypoint**: guided goals, simple forms, draft creation.
- **Advanced Promotions** (`tab=all`) — expert mode: list, raw JSON rules, orchestration, codes, cart simulation, and per-promotion **Advanced editor**.
- **Gift Cards & Store Credit** (`tab=gift-cards`) — dashboard, gift cards, store credit, and module **Settings** (`gift_cards_section=dashboard|gift-cards|store-credit|settings`); issue/adjust/void, hashed codes, ledger, checkout credit MVP, configurable gift card email (live preview)/sender/test mail, **sell via normal Woo products** ([docs/GIFT_CARDS_STORE_CREDIT.md](docs/GIFT_CARDS_STORE_CREDIT.md), [docs/GIFT_CARD_PRODUCTS.md](docs/GIFT_CARD_PRODUCTS.md)).
- **Getting Started** (`tab=getting-started`) — legacy onboarding tab (hidden from nav bar; still reachable by URL).
- **Settings** (`tab=settings`) — platform-wide Commerce Growth gates (telemetry, CSV export, simulations, cart discounts, automation, safe mode, data retention). Gift card–specific options moved to **Gift Cards & Store Credit → Settings**.
- **Compatibility status** on Reports and Diagnostics; **support bundle** JSON export on Diagnostics (no PII).
- See [docs/COMMERCIAL_READINESS.md](docs/COMMERCIAL_READINESS.md).

## Database

- **Schema version (option):** `mp_cp_schema_version` — current target is **`1.19.0`** (store credit wallets on gift card tables; prior **1.18.0** gift card ledger; **1.17.0** certification runs) (see `Schema::SCHEMA_VERSION`; adds `dry_run` on promotions; prior **1.15.0** added `discount_application_mode`).
- **Tables** (after activation / migration), using the site table prefix (e.g. `wp_`):
  - `{prefix}mp_cp_promotions` — promotion definitions (JSON-like rules in `LONGTEXT` columns).
  - `{prefix}mp_cp_redemptions` — usage against orders; **unique** `(order_id, promotion_id)` as **`order_promotion_unique`** (MySQL allows multiple `NULL` `order_id` rows; real checkouts use non-null `order_id`). Migration to **1.1.0** **refuses** `dbDelta` / version bump if duplicate non-null `(order_id, promotion_id)` pairs already exist (see `MigrationRunner`).
  - `{prefix}mp_cp_audit_log` — append-only audit trail.
  - `{prefix}mp_cp_promotion_codes` — manual promotion codes (hashed; **unique** `code_hash`). Optional **`batch_id`** links generated codes to **`mp_cp_code_batches`** (schema **1.4.0**; older rows may have `NULL`). Plain codes are **never** stored; admin UI shows only **`code_last4`** and **batch ID** after creation.
  - `{prefix}mp_cp_code_batches` — metadata for generated code batches (from schema **1.3.0**); plain codes are **not** stored on the batch row.
  - `{prefix}mp_cp_promotion_snapshots` — serialized promotion rollback rows (label/source; schema **1.11.0+**).
  - `{prefix}mp_cp_automation_runs` — automation execution history (schema **1.12.0**).
  - `{prefix}mp_cp_planner_telemetry` — aggregate planner counters per promotion, no PII (schema **1.12.0**).
- **Deactivation:** tables and `mp_cp_schema_version` are **not** removed; migrations are **additive** and intended to be **rollback-safe** (no `DROP TABLE` / data deletion in core flows).
- **Migrations:** `MigrationRunner` runs `dbDelta()` from Schema DDL on activation when the stored version is behind `Schema::SCHEMA_VERSION`. **1.1.0** adds the redemptions unique guard; if duplicates exist, the option is **not** advanced until data is fixed (see `MigrationRunner` / `WP_DEBUG` log).

## Domain and persistence

- **`Promotion`** / **`PromotionStatus`** / **`PromotionFactory`** — validated domain model; `conditions`, `actions`, and `restrictions` are PHP arrays in memory.
- **`PromotionRepository`** — reads/writes the `{prefix}mp_cp_promotions` table using `$wpdb` (`insert`, `update`, `find`, `find_by_uuid`, `find_by_id_or_uuid`, `find_active`, `delete`, `find_all`, `count_all`, **`find_filtered`**, **`count_filtered`**). JSON columns are stored as **LONGTEXT** via `wp_json_encode()`; loads decode defensively (invalid JSON becomes an empty array). No public REST layer; admin list is read-only for **editing** rows (creation uses `PromotionService`).
- **`Redemption`** / **`RedemptionRepository`** — **`{prefix}mp_cp_redemptions`** rows (**`insert`**, **`update`** status only, **`find_for_order`**, **`find_for_promotion`**, **`find_recorded_for_order_and_promotion`**, **`exists_for_order_and_promotion`**, **`count_for_order`**, **`count_recorded_for_promotion`**, **`count_reversed_for_promotion`**, **`count_recorded_for_promotion_code`**); statuses **`recorded`** / **`reversed`**; **no delete API**. From schema **1.1.0**, the table has a **unique** index **`order_promotion_unique`** on **`(order_id, promotion_id)`** (duplicate inserts for the same non-null pair fail at the database).
- **`UsageDiagnostics`** — read-only **`analyze()`** and manual **`repair()`** for promotion/code **`usage_count`** mismatches (audited when **`AuditLogger`** is wired).
- **`AuditLogEntry`** / **`AuditLogRepository`** — append-only writes to `{prefix}mp_cp_audit_log`; **raw IP addresses are never stored** (only a SHA-256 hash of a validated `REMOTE_ADDR` when present).
- **`AuditLogger`** / **`PromotionService`** — internal orchestration: `PromotionService::create_draft()` persists a draft and records `promotion.created`; `update_promotion()` records `promotion.updated`; `change_status()` applies allowed lifecycle transitions and records `promotion.status_changed`.
- **`PromotionCode`** / **`PromotionCodeFactory`** / **`PromotionCodeRepository`** — manual and generated codes; list helpers **`count_for_promotion`**, **`count_active_for_promotion`** for a promotion (`active` / `disabled` / `expired`); `hash('sha256', strtoupper(trim($plain)))` for lookup; optional **`batch_id`** on batch-generated codes (`NULL` for manually created single codes). Admins can **disable**, **re-enable**, or **mark expired** individual codes (audited as **`promotion_code.status_changed`**) or apply the same transitions **batch-wide** from batch detail (audited as **`promotion_code.batch_status_changed`**; one audit row per operation). **Expired** codes cannot be reactivated directly (only disabled). **No code or batch deletion.** **`bulk_update_status_for_batch`**, **`is_code_usable`**, **`insert`**, **`find`**, **`update`**, **`find_by_plain_code`**, **`find_for_promotion`**, **`find_for_batch`**, **`find_all`**, **`count_for_batch`**, **`increment_usage`**. Storefront: customers enter codes in the **WooCommerce coupon field**; **`PromotionCodeCouponBridge`** supplies virtual coupon data and validates via **`woocommerce_get_shop_coupon_data`** / **`woocommerce_coupon_is_valid`**. **`CartPromotionApplier`** applies the linked promotion fee when a matching code is on the cart; otherwise **automatic** promotions follow the planner (stackable / exclusive / exclusions / max applications). Code-linked promotions do **not** stack with automatic promotions. Order meta: **`_mp_cp_promotion_code_id`**, **`_mp_cp_promotion_code_last4`**.
- **`PromotionCodeBatch`** / **`PromotionCodeBatchRepository`** (`find`, `find_for_promotion`, **`count_for_promotion`**) / **`PromotionCodeBatchGenerator`** — admins generate up to **1,000** unique codes per batch on the promotion edit screen. Each generated code row stores **`batch_id`** for traceability. **Batch detail** (`?promotion={id}&batch={batch_id}`) is read-only: batch metadata plus linked codes (**last 4**, status, usage); full codes are **not** recoverable after generation. Codes use **`PREFIX-RANDOM`** (optional prefix) or **`RANDOM`** (12+ character cryptographically secure segment; excludes ambiguous **O/0/I/1**). Full codes are shown **once** in admin after generation (textarea + **Download CSV** on the same response); plain codes are **not** stored in the database (only **hashes**, **last 4**, and **batch_id**). CSV columns: `code`, `promotion_id`, `batch_id`, `generated_at` — download must happen **before** leaving or refreshing the page. Audited as **`promotion_code.batch_generated`**. **No PDF or email** yet.

## Evaluation pipeline

- **`EvaluationContext`** — generic inputs (`customer_id`, `cart_subtotal`, `currency`, `items`, `metadata`); no WooCommerce cart objects.
- **`CartContextBuilder`** (WooCommerce) — read-only mapping from `WC()->cart` into **`EvaluationContext`** (product line summaries + `product_cat` term IDs); returns an empty context when WooCommerce or the cart is unavailable.
- **Conditions** — implement `ConditionInterface::evaluate()` and return **`ConditionResult`** (pass/fail with optional message).
- **Actions** — implement `ActionInterface::preview()` and return **`ActionResult`** (type + payload). The evaluator uses previews only; **`CartPromotionApplier`** turns the first eligible **`percentage_discount`** or **`fixed_amount_discount`** into a cart fee on the storefront.
- **`PromotionEvaluator::evaluate()`** — loads rule objects from a **`Promotion`**’s `conditions` / `actions` arrays only; **no database, WooCommerce, or audit calls** in this phase.
- **Demo types supported:** conditions **`minimum_subtotal`**, **`minimum_eligible_subtotal`**, **`maximum_eligible_subtotal`**, **`product_quantity`**, **`category_quantity`**, **`product_in_cart`**, **`category_in_cart`**, **`exclude_sale_items`**, **`logged_in`**, **`first_order`**, **`customer_role`**, **`billing_country`** (ISO codes from Woo billing), **`customer_email_domain`** (email domain from Woo/user), **`customer_redemption_count`** (logged-in redemption total from DB), **`minimum_cart_quantity`** / **`maximum_cart_quantity`**; actions **`percentage_discount`** and **`fixed_amount_discount`** (optional product/category/variation scope and sale exclusion on percentage), **`free_shipping`** (MVP: negative fee = shipping total), **`cheapest_item_discount`** (variation-aware unit targeting via `EligibleCartScope`, optional sale exclusion), and **`free_gift_product`** (adds configured product to cart at zero price). Promotion-level **`excluded_product_ids`** / **`excluded_category_ids`** (schema **1.8.0**) narrow evaluation scope without removing cart lines. **Simple Rule Builder v0** covers targeting conditions, product/category exclusions on edit, cheapest item **variation IDs** and sale exclusion. **`PromotionPlanner`** selects promotions per application rules; each selected promotion uses only its **first** supported action. **Fixed amount** / **percentage** / **cheapest item** discounts are clamped per fee and **cumulative total ≤ cart subtotal**. Manual checks: [docs/manual-scoped-discount-test.md](docs/manual-scoped-discount-test.md), [docs/manual-product-targeting-test.md](docs/manual-product-targeting-test.md), [docs/manual-cheapest-item-test.md](docs/manual-cheapest-item-test.md). **`free_shipping`** requires browser checkout verification when shipping methods apply.
- **Promotion templates** — ten admin presets on the edit screen (`PromotionTemplate` service), including VIP/loyal/returning customer segmentation templates; see [docs/manual-promotion-templates-test.md](docs/manual-promotion-templates-test.md) and [docs/manual-orchestration-and-segmentation-test.md](docs/manual-orchestration-and-segmentation-test.md).
- **Admin cart preview** — **Edit promotion** can run **`PromotionEvaluator`** against the current session cart and show the **promotion plan** table (selected / skipped reason), **plan explanation** bullets (`PromotionPlanExplainer`), and **conflict analysis** across active promotions (`PromotionConflictAnalyzer`); **admin-only**, **no persistence**, **does not add cart fees**.
- **Conflict analysis (heuristic)** — read-only warnings for mutual exclusions, exclusive vs stackable overlap, scoped discount overlap, max applications, duplicate free shipping/gifts, usage limits, and priority shadowing; see [docs/manual-conflict-analysis-test.md](docs/manual-conflict-analysis-test.md).
- **Storefront cart (v1)** — **`CartPromotionApplier`** runs on **`woocommerce_cart_calculate_fees`** (priority **20**) for fee actions and **`free_gift_product`** cart adds; **`woocommerce_before_calculate_totals`** (priority **20**) zeroes gift line prices. **`PromotionPlanner`** on **`find_active()`** promotions; paid subtotal cap for discount fees. Session **`mp_cp_applied_promotion`** includes **`applied_promotions[]`**. **`OrderPromotionRecorder`** records redemptions (including **`discount_amount` 0** for gifts). Reversal does not remove gift order lines. Toggle with **`mp_cp_enable_cart_discounts`** (default `true`).

## Admin UI

Admin navigation (under **WooCommerce**):

```text
WooCommerce
└── Promotions
    ├── Campaign Builder tab (default) (?page=mp-commerce-promotions)
    ├── Advanced Promotions tab (?page=mp-commerce-promotions&tab=all)
    ├── Gift Cards tab       (?page=mp-commerce-promotions&tab=gift-cards&gift_cards_section=…)
    ├── Settings tab         (?page=mp-commerce-promotions&tab=settings)
    ├── Diagnostics tab      (?page=mp-commerce-promotions&tab=diagnostics)
    └── Reports tab          (?page=mp-commerce-promotions&tab=reports)
```

A single sidebar item (**Promotions**) routes through **`AdminRouter`** using the **`tab`** query arg (default **`campaign-builder`**). In-page **nav tabs** include **Getting Started**, **Campaign Builder**, **Advanced Promotions**, **Reports**, **Diagnostics**, and **Settings** (WordPress `nav-tab` styling). Legacy URLs (`page=mp-commerce-promotions-settings` or `page=mp-commerce-promotions-diagnostics`) redirect to the matching tab.

- **Advanced Promotions list** — row checkboxes and bulk **Activate**, **Pause**, and **Archive** (POST + nonce; respects allowed status transitions; no delete). Hard delete is not offered in admin.
- **Promotion edit** — exclusion helper table (latest 25 promotions with checkboxes) plus comma-separated IDs for additional exclusions.
- **Reports** — read-only **`PromotionReports`** summaries (total/active promotions, recorded/reversed redemption counts, recorded discount total, top 10 promotions) with filters on **`redeemed_at`** (inclusive date range), promotion ID, and redemption status. **Export redemptions CSV** (POST + nonce) returns up to **5,000** rows; does **not** expose raw promotion codes (the `code` column may be empty).

- **Advanced Promotions** lists promotions with **search** (`s`), **status filter** (`promotion_status`: draft / active / paused / archived), and **pagination** (`paged`, 20 per page). Table columns include **Codes** (active / total), **Batches**, **Redemptions** (recorded / reversed), and **Validation** (rule validator error/warning summary). Supports **Create draft promotion** (name + nonce; redirect returns to the unfiltered list). **Edit** opens a detail page (`promotion` query arg: numeric id or UUID; edit screen omits tabs and shows **← Back to promotions**). **Code batch detail** uses `batch` on the same edit URL (`?page=mp-commerce-promotions&tab=all&promotion={id}&batch={batch_id}`): read-only batch metadata and up to **100** linked codes (**last 4** only); invalid or mismatched batch IDs show an admin error and the normal edit screen.
- **Settings** provides an **Enable cart discounts** checkbox (stored as **`mp_cp_cart_discounts_enabled`**, default **yes**).
- **Diagnostics** uses **`UsageDiagnostics`** to compare stored **`usage_count`** values on promotions and codes against computed counts from **recorded** / **reversed** redemptions (and order meta **`_mp_cp_promotion_code_id`** for codes). Mismatches are highlighted. Admins can run **Repair Usage Counters** (POST + nonce + confirm): **`repair()`** recalculates mismatched counters from recorded redemptions; **no rows are deleted**; repairs are **audited** (`promotion.usage_repaired`, `promotion_code.usage_repaired`). There is **no automatic or scheduled repair**.
- **Status** is changed only via **controlled POST actions** (Activate / Pause / Archive); **archived** promotions **cannot be reactivated**. The edit screen is organized by workflow: header summary, status actions (including **Duplicate promotion**), promotion details, rules (simple builder, ID helper, templates, raw JSON), rule validation, cart preview, promotion codes (including batch generation), redemptions, and audit log. **Duplicate promotion** creates a new **draft** via **`PromotionService::duplicate_as_draft()`** with name **Copy of {original}**, copying description, priority, dates, conditions, actions, restrictions, and usage limit; it does **not** copy status, usage count, codes, batches, redemptions, or audit history (audited as **`promotion.duplicated`**). The main form edits name, description, priority, dates, and **raw JSON** for conditions, actions, and restrictions (validated as JSON arrays). The edit screen includes a **Simple Rule Builder** (server-side POST only) that writes **one condition** and **one action** to the promotion via **`PromotionService::update_promotion()`** without changing name, status, priority, dates, or restrictions; **raw JSON** fields remain for advanced edits. **Product quantity** and **category quantity** conditions require numeric WooCommerce **product post IDs** and **product category term IDs**; the edit screen shows a **Product and category IDs** helper (links to Products and Product categories admin lists, plus optional recent products/categories tables). Copyable **rule templates** provide readonly JSON examples. A read-only **Rule Validation** panel (**`PromotionRuleValidator`**) that checks stored JSON against supported condition/action types (unknown types, missing fields, no actions, multiple actions, status hints). It does **not** guarantee cart eligibility. **Promotion Codes** on the edit screen let admins create **manual** codes (hashed at rest; list shows **last 4** only), **disable / enable / mark expired** individual codes (POST; no delete; expired cannot return to active), and open **batch detail** for generated batches; customers redeem via the **WooCommerce coupon field**. **Preview against current cart** runs **`PromotionEvaluator`** in-memory using **`CartContextBuilder`** (no DB writes; **does not** add storefront fees). Results include **condition/action trace** tables with stable `reason_code` values for admin debugging (not shown on the storefront). The promotion detail page also shows **read-only** **Usage / Redemptions** (latest 25 rows from **`RedemptionRepository::find_for_promotion`**) and **Audit Log** (latest 25 from **`AuditLogRepository::find_for_promotion`**, context as pretty JSON). **Hard delete UI**, **multi-condition builders**, and **REST/AJAX** are **not** implemented yet.

## Application planning (stacking)

Each promotion has **application rules** (`exclusive` or `stackable`, **stop processing**, optional **max applications** plan cap, **excluded promotion IDs**). **Max applications** limits how many promotions may be selected in one cart plan (not per-customer usage); the cap is the minimum among selected promotions that define it. `PromotionPlanner` builds an evaluation plan with skip reasons when multiple promotions are eligible; exclusions skip listed promotion IDs evaluated **after** a selected promotion (priority/order matters). **Stackable** promotions with **stop processing** off can each add a cart fee; **total discount is capped at cart subtotal**. **Exclusive** promotions still stop further selections. **Promotion codes** do not stack with automatic promotions yet.

## WooCommerce compatibility

- **HPOS (High-Performance Order Storage)** — compatibility with `custom_order_tables` is declared to WooCommerce via `FeaturesUtil` when available. Order promotion metadata is written through `WC_Order` CRUD (`update_meta_data` / `save`), which works with HPOS and legacy post-based orders.
- **Cart & Checkout Blocks** — **declared** compatible (`passed` QA 2026-05-18). QA pages **4333** / **4334** (published; not live cart/checkout). Browser fee + coupon COD Pass (orders **4362**, **4363**); Store API checkout recording hook; CLI cert 8/8. Residual: block line-item unit display; free-shipping offset. Code batch: **`BLOCKQA239`** (legacy `BLOCKQA218` → archived promo). See [docs/BLOCKS_QA_EVIDENCE_2026-05-18.md](docs/BLOCKS_QA_EVIDENCE_2026-05-18.md).

## Known limitations (MVP)

- **Stacking** — multiple fees only when promotions are **stackable** with **stop processing** off; **exclusive** (default) still stops after one selection; **codes** do not stack with automatic promotions.
- **Actions** — only **`percentage_discount`** and **`fixed_amount_discount`** (cart fee); no BOGO, free shipping, or line-item discounts.
- **Conditions** — cart, customer, and location types including **`billing_country`** and **`customer_email_domain`** (see evaluation pipeline; Woo cart context enriches metadata when session APIs are available).
- **Codes** — manual entry or batch generation (max **1,000** per batch); full codes shown once (copy or CSV download before refresh); batch detail shows metadata, **last 4**, per-code actions, and **batch-wide** disable/enable/expire (no delete; expired cannot return to active). Virtual coupon amount is **0** (discount is the cart fee). No PDF/email export.
- **Orders** — partial refunds not handled; reversal is idempotent per order/promotion.
- **Diagnostics** — scans up to **100** promotions/codes per run; repair is manual (POST on Diagnostics tab), not scheduled.
- **Admin** — no promotion hard-delete UI (`PromotionRepository::delete` exists for internal/tests only).

## Install (development)

Copy this folder into `wp-content/plugins/`, then activate **MP Commerce Promotions** in the WordPress admin.

On this VPS, commit in the staging tree and deploy to the live plugin directory with:

```bash
bash scripts/sync-to-live.sh
bash scripts/verify-plugin.sh
```

**Plugin version:** `0.4.0` (`MP_COMMERCE_PROMOTIONS_VERSION` in `mp-commerce-promotions.php`). **Stable release** — see [docs/GITHUB_RELEASE_NOTES_0.4.0.md](docs/GITHUB_RELEASE_NOTES_0.4.0.md). **0.3.0-pilot.4 and earlier pilots are superseded** for new production ZIP installs. Database schema version is separate (`mp_cp_schema_version`, currently **1.19.0**). Storefront discounts default to **fee-based**; **line_item** / **hybrid** modes are experimental (see `PromotionDiscountApplicationMode`).

**Production release zip** (runtime files only — no `scripts/`, `tests/`, `docs/`, `.github/`, `.git/`, or `vendor/`):

```bash
bash scripts/build-zip.sh
bash scripts/release-audit.sh
```

Output: `/home/magpern/mp-commerce-promotions-staging/build/mp-commerce-promotions-0.4.0.zip`. See [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md). Pushing a `v*` tag runs [.github/workflows/release.yml](.github/workflows/release.yml) to attach the ZIP to a GitHub Release.

### GitHub Release updater (production)

On sites with `WP_ENVIRONMENT_TYPE` set to `production`, the plugin checks `https://api.github.com/repos/magpern/mp-commerce-promotions/releases/latest` and offers updates when a newer **stable** release exists with asset `mp-commerce-promotions-X.Y.Z.zip`.

**Disable on development/staging** (recommended for this repo checkout):

```php
// wp-config.php
define( 'MP_CP_DISABLE_GITHUB_UPDATER', true );
```

Or: `add_filter( 'mp_cp_github_updater_enabled', '__return_false' );`

**Latest stable:** [v0.4.0](https://github.com/magpern/mp-commerce-promotions/releases/tag/v0.4.0)

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for paths, exclusions (never copy `.git` or `vendor` to live), Composer lint/test commands (`composer run test` — pure PHP unit tests only; WordPress integration tests are future work).

## Manual testing

- Storefront checkout, fees, redemption, reversal: [docs/manual-checkout-test.md](docs/manual-checkout-test.md)
- Stacking, caps, exclusions, max applications: [docs/manual-stacking-test.md](docs/manual-stacking-test.md)
- Promotion codes and coupon field: [docs/manual-promotion-code-test.md](docs/manual-promotion-code-test.md)

## Uninstall

Custom tables and plugin options are **retained on uninstall** in the current MVP to prevent accidental data loss. See `uninstall.php` and readme.txt for the policy; a future explicit “delete all data” setting may be added.
