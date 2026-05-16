# MP Commerce Promotions — Architecture

## Project Identity

**Public plugin name:** Commerce Promotions for WooCommerce  
**Plugin slug:** `mp-commerce-promotions`  
**Text domain:** `mp-commerce-promotions`  
**PHP namespace:** `MP\CommercePromotions`  
**Repository:** `https://github.com/magpern/mp-commerce-promotions`

This project is a generic WooCommerce extension, not a store-specific plugin. The long-term direction is a lightweight commerce promotion engine that can grow into voucher, campaign, partner, and loyalty functionality while remaining WooCommerce-compatible and marketplace-ready.

---

## Core Architectural Principle

This plugin is not a simple coupon plugin.

It is a rule-driven promotion engine built around:

```text
Promotion
├── Conditions
├── Actions
├── Restrictions
├── Evaluation context
├── Usage tracking
├── Audit logging
└── Operational tooling
```

Promotion behavior should remain data-driven where possible. New promotion types should generally be expressed through new condition/action classes rather than hardcoded one-off checkout logic.

---

## Current System Status

The current MVP foundation includes:

- Plugin scaffold and PSR-4 style autoloading
- Database migration framework
- Custom promotion tables
- Promotion domain model and repository
- Rule evaluation pipeline
- Admin promotion list and edit screens
- Simple Rule Builder v0
- Raw JSON rule editing
- Rule validation panel
- Product/category ID helper tables
- Percentage and fixed-amount discounts
- Minimum subtotal, product quantity, and category quantity conditions
- WooCommerce cart integration using negative fees
- Admin cart preview
- Promotion code foundation
- Manual promotion codes
- Generated code batches
- Show-once generated code display and CSV download
- Batch/code traceability
- Code and batch status actions
- Promotion redemption tracking
- Order meta recording
- Idempotency protection
- Cancellation/refund reversal handling
- Audit logging
- Diagnostics and manual usage repair
- Admin search, filters, pagination, and operational metadata columns
- Settings kill switch for cart discounts

---

## Filesystem and Development Context

Main WooCommerce project root:

```text
/home/magpern/woocommerce
```

Primary Git working tree:

```text
/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions
```

Live plugin directory:

```text
/home/magpern/woocommerce/wp-content/plugins/mp-commerce-promotions
```

Development should happen in the staging Git working tree first. Sync to the live plugin directory only after verification. Never copy `.git/` into the live WordPress plugin directory.

---

## Environment Assumptions

- Ubuntu 24.04 VPS
- Docker + Docker Compose v2
- WordPress + WooCommerce
- MariaDB
- Docker Compose project name: `woocommerce`
- WordPress container uses uid `33` / `www-data`
- `wp-content` is bind-mounted
- Permissions matter heavily
- Production-safe changes are preferred

---

## Git Workflow

Each meaningful phase should follow:

```text
Implement
→ Verify
→ Commit
→ Push
→ Sync to live
→ Verify live
→ Continue
```

Recommended commit style:

```text
feat: add ...
fix: ...
refactor: ...
chore: ...
docs: ...
```

Do not mix unrelated changes in one commit. Larger bundled tasks are acceptable when they are tightly related, such as an admin UX bundle or a code-management bundle.

---

## Database Architecture

The plugin uses custom tables rather than storing the entire promotion system in WooCommerce coupons or post meta.

Current tables include:

```text
wp_mp_cp_promotions
wp_mp_cp_redemptions
wp_mp_cp_audit_log
wp_mp_cp_promotion_codes
wp_mp_cp_code_batches
```

The schema is managed through:

```text
src/Infrastructure/Database/Schema.php
src/Infrastructure/Database/MigrationRunner.php
```

### Migration Rules

- Migrations must be additive and rollback-safe.
- No destructive operations during activation.
- No `DROP TABLE`.
- No bulk `DELETE`.
- Schema version is stored in the WordPress option `mp_cp_schema_version`.
- `dbDelta()` is used for table/index evolution.
- Post-migration verification should confirm critical tables/indexes exist before bumping schema version.

### Current Schema Version

```text
1.6.0
```

---

## Domain Layer

Domain objects should represent business concepts without direct WooCommerce coupling.

Important domain classes:

```text
Promotion
PromotionStatus
PromotionRepository
PromotionCode
PromotionCodeRepository
PromotionCodeFactory
PromotionCodeBatch
PromotionCodeBatchRepository
Redemption
RedemptionRepository
AuditLogEntry
AuditLogRepository
```

### Domain Rules

- Domain models should validate required invariants.
- Domain models should not directly perform database writes.
- Repositories own persistence.
- Domain objects should avoid WooCommerce-specific types where possible.
- Raw promotion codes must never be persisted.

---

## Service Layer

Services coordinate business operations across repositories and infrastructure.

Important services:

```text
PromotionService
AuditLogger
Settings
PromotionRuleValidator
SimpleRuleBuilder
UsageDiagnostics
PromotionCodeBatchGenerator
PromotionCodeBatchGenerationOutcome
```

### Service Rules

- Services may coordinate repositories.
- Services may write audit entries.
- Services should not render admin UI.
- Services should not directly depend on request globals unless explicitly designed for WordPress integration.

---

## Promotion Rule Engine

The rule engine is centered around:

```text
EvaluationContext
EvaluationResult
ConditionTrace
ActionTrace
PromotionEvaluator
RuleTypes
RuleRegistry
ConditionInterface
ActionInterface
```

### Evaluation trace (explainability)

Each promotion evaluation can return structured traces on `EvaluationResult`:

- **`condition_traces`** — one entry per condition evaluated (type, pass/fail, `reason_code`, message, config snapshot, observed values).
- **`action_traces`** — one entry per configured action (type, selected flag, `reason_code`, message, config, preview payload).

Reason codes are **internal, stable strings** (for example `cart_value_too_low`, `condition_unknown`, `action_selected`, `action_not_reached`). They are intended for admin debugging and future tooling — not storefront output.

When conditions fail, actions receive `action_not_reached` traces without preview evaluation. When eligible, only the **first** action trace is marked `action_selected` (matching storefront application: one action per promotion), while additional configured actions may still appear in `action_results` previews.

The admin **Cart preview** on the promotion edit screen renders trace tables (escaped; observed/preview as JSON). No REST/AJAX or customer-facing explainability in this phase.

### Rule type identifiers (`RuleTypes`)

`MP\CommercePromotions\Engine\RuleTypes` centralizes the canonical string identifiers for supported conditions and actions (for example `minimum_subtotal`, `percentage_discount`). Condition/action classes return these constants from `get_type()`; services and the evaluator compare against the same values.

### Supported types (`RuleRegistry`)

`MP\CommercePromotions\Engine\RuleRegistry` lists the engine types that are implemented today:

- `supported_conditions()` / `is_supported_condition()`
- `supported_actions()` / `is_supported_action()`

`PromotionRuleValidator` and `SimpleRuleBuilder` use the registry for allow-lists. **Dynamic registration** (plugins registering new types at runtime) is **not** implemented yet; adding a type requires updating `RuleTypes`, `RuleRegistry`, and the corresponding condition/action class plus evaluator wiring.

### Current Conditions

```text
minimum_subtotal
product_quantity
category_quantity
logged_in
first_order
customer_role
billing_country
customer_email_domain
customer_redemption_count
```

`logged_in` passes when `EvaluationContext::get_customer_id()` is a positive integer.

`first_order` passes when context metadata `has_previous_orders` is explicitly `false`. For logged-in carts, `CartContextBuilder` sets this via `wc_get_orders()` (limit 1; statuses `completed`, `processing`, `on-hold` only). If lookup fails, the key is omitted and the condition fails safely.

`customer_role` passes when any configured WordPress **role slug** in the condition JSON matches a slug in metadata `customer_roles` (case-insensitive comparison). `CartContextBuilder` populates `customer_roles` from the logged-in user object when available.

`billing_country` passes when metadata `billing_country` (ISO code) is in the configured `countries` list (uppercase comparison). `CartContextBuilder` sets this from `WC()->customer->get_billing_country()` or user meta `billing_country` when available (logged-in or guest session).

`customer_email_domain` passes when the domain part of metadata `customer_email` matches a configured domain (case-insensitive). `CartContextBuilder` sets email from the user account or `WC()->customer->get_billing_email()` when available.

`customer_redemption_count` compares metadata `customer_redemption_count` (integer) using `QuantityComparator` operators against JSON `count`. For logged-in carts, `CartContextBuilder` sets this via `RedemptionRepository::count_recorded_for_customer()` when the repository is wired. Guests omit the key and the condition fails safely.

**Simple Rule Builder v0** supports all current condition and action types (including customer/location conditions, `free_shipping`, `cheapest_item_discount`, and `free_gift_product`). Admin cart preview summarizes `discount_amount`, `discounted_units`, and `not_applicable` for cheapest-item actions. See [manual-cheapest-item-test.md](manual-cheapest-item-test.md) and [manual-free-gift-test.md](manual-free-gift-test.md).

### Current Actions

```text
percentage_discount
fixed_amount_discount
free_shipping
cheapest_item_discount
free_gift_product
```

`free_shipping` preview returns `{ "free_shipping": true }`. On the storefront, `CartPromotionApplier` adds a **negative cart fee** equal to the current WooCommerce shipping total when it is **> 0** (MVP fee-offset; not native shipping-method manipulation). Fee labels: `Commerce promotion: Free shipping - {name}` or `Commerce promotion code: Free shipping ****{last4}`. When shipping is zero or unavailable, no fee is added. Free shipping does not consume the cart subtotal discount cap allowance.

`cheapest_item_discount` is **BOGO groundwork**: `CartItemSelector` filters cart line items by **category** or **product** scope, expands quantities into unit-price entries, and discounts the cheapest `discounted_quantity` units when eligible unit count ≥ `required_quantity`. Preview payload includes `discount_amount`, `discounted_units`, and `scope` (or `not_applicable` when quantity is insufficient). Storefront applies `discount_amount` as a negative fee (subtotal cap applies). Does **not** add free products, change line prices, or split cart lines. Examples: buy 3 in category get 1 free (`discount_percentage` 100, `required_quantity` 3, `discounted_quantity` 1); 50% off cheapest eligible product unit.

`free_gift_product` preview returns `{ "product_id", "quantity", "variation_id?" }`. Storefront: `FreeGiftCartHandler` calls `WC_Cart::add_to_cart()` with cart item metadata (`mp_cp_free_gift=yes`, promotion id/uuid/name). Duplicate gifts for the same promotion + product/variation are skipped. `CartPromotionApplier::zero_free_gift_line_prices()` runs on `woocommerce_before_calculate_totals` (priority 20) to set gift line price to **0**. No negative fee for this action. Paid cart subtotal (excluding gift lines) drives eligibility and discount caps. Order recording stores `discount_amount` **0**; reversal does **not** remove gift lines from placed orders.

**Cart line items** in `EvaluationContext` include `product_id`, `variation_id`, `quantity`, `line_subtotal`, `categories`, and when available from Woo: `unit_price`, `item_key`, `product_name`.

### Engine Rules

- Conditions return pass/fail results.
- All conditions must currently pass.
- Actions return previews before WooCommerce application.
- Only the first supported action per promotion is applied on the storefront.
- Unknown condition/action types should fail safely.
- Evaluation should not mutate cart/order/database state.

### Application planning (stacking)

Each promotion row stores application strategy fields:

- **`application_mode`** — `exclusive` (default) or `stackable` (multiple selections when stop processing is off).
- **`stop_processing`** — when true (default), no further promotions are selected in a plan after this one is selected.
- **`max_applications`** — optional plan-level cap on how many promotions may be selected in one evaluation (not per-customer usage). When set on any selected promotion, the active cap is the **minimum** among those values; further eligible promotions are skipped with `max_applications_reached`.
- **`excluded_promotion_ids`** — JSON array of promotion IDs to skip when this promotion is selected (evaluated later in the plan only).

`PromotionPlanner::plan()` evaluates promotions in caller order (typically priority, then id), builds a `PromotionEvaluationPlan` of `PromotionEvaluationDecision` entries (selected flag + `skipped_reason`: `not_eligible`, `blocked_by_exclusive_promotion`, `stopped_processing`, `excluded_by_selected_promotion`, `max_applications_reached`), optional decision `metadata`, and does not apply cart fees. When a promotion is selected, its exclusion IDs are added to an active set; later eligible promotions in that set are skipped (priority/order matters — exclusions do not affect promotions evaluated before the excluder).

`CartPromotionApplier` uses the planner for automatic promotions and may apply **multiple negative fees** when several stackable promotions are selected (`stop_processing=false`). Each selected promotion still uses only its **first supported action**. **Cumulative discount is capped at cart subtotal.** Code-linked promotions (coupon field) still evaluate **only the linked promotion** — automatic promotions do not stack on top.

Session key `mp_cp_applied_promotion` includes `applied_promotions[]` plus legacy top-level fields for the first entry. Checkout writes `_mp_cp_applied_promotions` (JSON) and one redemption row per promotion.

---

## WooCommerce Integration

WooCommerce integration is isolated under:

```text
src/Woo/
```

Important classes:

```text
WooCommerceBridge
WooCompatibility
CartContextBuilder
CartPromotionApplier
FreeGiftCartSynchronizer
OrderPromotionState
OrderPromotionRecorder
PromotionCodeCouponBridge
PromotionReports (admin Reports tab)
```

### Feature compatibility (HPOS)

`WooCompatibility` registers on `before_woocommerce_init` and declares compatibility with WooCommerce **High-Performance Order Storage** (`custom_order_tables`) when `FeaturesUtil` exists. No fatal occurs if WooCommerce is inactive or an older version lacks the API.

**Cart & Checkout Blocks** (`cart_checkout_blocks`) is **not** declared: discounts use cart fees and the standard coupon field; block checkout compatibility has not been verified end-to-end in the browser. Omitting the declaration avoids a false “compatible” label until tested.

### HPOS-safe order metadata

- **`OrderPromotionState`** — single helper for `_mp_cp_applied_promotions` JSON, legacy primary meta, `_mp_cp_redemption_recorded`, and `_mp_cp_redemption_reversed`.
- **`OrderPromotionRecorder`** — checkout idempotency (unique redemption rows + early exit when all promotions already recorded); reversal once per promotion; restore on `processing`/`completed` after reversal; audit `promotion.recorded_on_order` / `promotion.reversed_on_order` (plus legacy `promotion.redeemed` / `promotion.redemption_reversed`).
- **`FreeGiftCartSynchronizer`** — on each cart totals pass, removes stale/orphan `mp_cp_free_gift` lines and normalizes quantities; audit `promotion.gift_added_to_cart` / `promotion.gift_removed_from_cart`.
- **`RedemptionRepository::count_recorded_for_promotion_code()`** — read-only join against `wp_wc_orders_meta` when HPOS is enabled, else `wp_postmeta`; uses `WooCompatibility::is_hpos_enabled()`.
- **`UsageDiagnostics`** — derives counts from custom redemption tables and the repository above; no direct `wp_posts` order assumptions.

### Current WooCommerce Behavior

- Active automatic promotions can apply through a negative cart fee.
- Manual promotion codes are entered through the standard WooCommerce coupon field.
- Virtual WooCommerce coupon data is used for known promotion codes.
- No native WooCommerce coupon posts are created.
- Checkout records promotion usage to custom tables and order meta.
- Reversal hooks handle cancelled, failed, refunded, trashed, and deleted orders where possible; paid-status hooks can restore previously reversed rows.
- Free gift lines sync with planner-selected promotions (manual non-gift lines are untouched).

### Current Discount Strategy

The MVP uses negative fees:

```text
WC()->cart->add_fee( $label, -$discount, false )
```

This is acceptable for MVP testing but should be reviewed before marketplace/commercial release because negative fees can affect reporting, tax behavior, and compatibility expectations.

---

## Order and Redemption Tracking

Order metadata keys currently include:

```text
_mp_cp_promotion_id
_mp_cp_promotion_uuid
_mp_cp_promotion_name
_mp_cp_discount_amount
_mp_cp_action_type
_mp_cp_percentage
_mp_cp_fixed_amount
_mp_cp_promotion_code_id
_mp_cp_promotion_code_last4
_mp_cp_redemption_recorded
_mp_cp_redemption_reversed
```

### Idempotency

Redemption recording is protected against duplicate order/promotion rows by:

- Application-level duplicate checks
- Order meta markers
- Database-level unique index on `(order_id, promotion_id)`

### Reports (admin)

**`PromotionReports`** (Reports tab) provides read-only aggregates from `{prefix}mp_cp_redemptions` and `{prefix}mp_cp_promotions`:

- Summary: total/active promotions, recorded/reversed counts, sum of **recorded** `discount_amount`, top 10 promotions by recorded count.
- Filters: inclusive calendar range on **`redeemed_at`**, optional `promotion_id`, optional `status` (`recorded` / `reversed`).
- CSV export (POST + nonce): up to **5,000** rows; columns include redemption metadata only — **not** merchant plain-text promotion codes (the `code` column may be null).

### Reversal

Order cancellation/refund reversal currently:

- Marks redemption as reversed
- Decrements promotion usage count once
- Decrements promotion code usage count once when code metadata exists
- Writes audit entry
- Does not support proportional partial refund logic yet

---

## Promotion Codes and Batches

Manual and generated promotion codes are supported.

### Security Model

- Plain codes are never stored in the database.
- Codes are normalized and hashed with SHA-256.
- Only the last 4 characters are stored for display.
- Generated codes are shown once after generation.
- CSV download is also show-once.

### Code Batch Behavior

- Batches are stored in `wp_mp_cp_code_batches`.
- Generated codes are linked to the creating batch by `batch_id`.
- Admins can inspect batch metadata and linked code metadata.
- Full codes cannot be recovered after leaving or refreshing the show-once result screen.

---

## Admin Architecture

Admin classes live under:

```text
src/Admin/
```

Current admin classes:

```text
AdminMenu
AdminRouter
AdminNavigation
PromotionsPage
PromotionEditPage
SettingsPage
DiagnosticsPage
```

### Current Navigation

The WooCommerce sidebar should show one plugin entry:

```text
WooCommerce
└── Promotions
```

Inside the plugin screen:

```text
All Promotions | Settings | Diagnostics
```

The main admin route is:

```text
admin.php?page=mp-commerce-promotions
```

Tabs are routed with:

```text
tab=all
tab=settings
tab=diagnostics
```

### Admin Security Rules

All admin state-changing actions require:

- `manage_woocommerce` capability
- POST request
- Nonce validation
- `wp_unslash()` before sanitization
- Sanitized inputs
- Escaped output
- Redirect-after-POST when appropriate

---

## Diagnostics and Repair

Diagnostics currently check usage counter consistency for:

- Promotions
- Promotion codes

The repair tool:

- Recomputes expected usage counters from recorded redemptions/order metadata
- Updates mismatched counters
- Writes audit entries
- Does not delete rows
- Is manual only
- Is not scheduled or automatic

Current limitation: diagnostics are capped at 100 promotions and 100 codes per run.

---

## Security Principles

The project should continue to follow:

- Capability checks on all admin actions
- Nonces on all state-changing requests
- Sanitization of all input
- Escaping of all output
- No raw code persistence
- No direct SQL unless isolated to repositories/migrations
- Prepared SQL for injected values
- No WooCommerce core modifications
- No destructive activation/deactivation behavior
- Defensive handling of missing WooCommerce components

---

## Marketplace Readiness Direction

The plugin should remain compatible with future WooCommerce Marketplace or WordPress.org expectations.

Planned marketplace-readiness work includes:

- `readme.txt`
- `uninstall.php`
- Composer autoloading
- PHPCS/WPCS baseline
- PHPUnit tests
- CI workflow
- i18n review
- Text-domain loading
- Screenshots/assets
- Settings API polish
- Release packaging
- Semantic versioning
- Compatibility matrix

---

## Known MVP Limitations

Current limitations include:

- Negative-fee discount strategy
- Exclusive promotions still allow only one selected promotion in a plan
- Only first supported action per promotion is applied
- Stackable multi-fee, exclusions, and max_applications are plan-level (not per-customer usage limits)
- `free_gift_product` is MVP (configured IDs only; zero cart price; no stock reservation); `cheapest_item_discount` remains fee-offset BOGO groundwork only
- `free_shipping` is fee-offset only (verify in browser checkout; block checkout not declared)
- No partial refund proportional reversal
- No advanced customer segmentation beyond role/country/email/redemption count
- No product/category search in builder yet
- No persistent access to generated full codes
- No PDF/email delivery yet
- No partner/reseller logic yet
- No public API yet
- No automated test suite yet

---

## Recommended Roadmap

### Phase A — Foundation/Core Engine

Mostly complete.

Includes:

- Plugin structure
- Migrations
- Repositories
- Domain models
- Evaluation engine
- Woo bridge
- Redemption tracking
- Reversal handling
- Audit logging

### Phase B — Usable Admin MVP

In progress / mostly complete.

Includes:

- Admin list improvements
- Rule validation
- Simple builder
- Code and batch management
- Diagnostics and repair
- Usability cleanup

### Phase C — Real Promotion Mechanics

Recommended next major area.

Includes:

- Free shipping action
- BOGO
- Cheapest item free
- Cart item targeting
- Product/category exclusions
- Stackability rules
- Promotion conflict handling
- Variation-specific conditions

### Phase D — Customer and Campaign Logic

Includes:

- First-order-only condition
- Logged-in/customer role conditions
- Customer-specific codes
- Per-customer usage limits
- Country restrictions
- Email/signup issuance
- Campaign ownership

### Phase E — Distribution and Delivery

Includes:

- PDF vouchers
- Email delivery
- CSV export improvements
- Batch delivery logs
- Partner/reseller pools
- QR/barcode support

### Phase F — Analytics and Operations

Includes:

- Reporting dashboard
- Redemption analytics
- Campaign ROI
- Code usage reports
- Fraud/anomaly detection
- Diagnostics pagination
- Scheduled maintenance tools

### Phase G — Marketplace/Commercial Readiness

Includes:

- Composer
- PHPCS
- PHPUnit
- CI
- readme.txt
- uninstall policy
- versioning
- packaging
- documentation

---

## Extension Guidelines

When adding new functionality:

1. Decide whether it is a condition, action, restriction, service, admin workflow, or Woo integration.
2. Keep domain logic out of admin classes.
3. Keep WooCommerce-specific logic under `src/Woo/`.
4. Add repository methods instead of ad-hoc SQL.
5. Add audit events for meaningful state changes.
6. Prefer additive migrations.
7. Verify with WP-CLI and commit each phase.
8. Sync to live only after commit and verification.

---

## Architectural Non-Goals

The plugin should not:

- Modify WooCommerce core
- Modify WordPress core
- Depend on theme hacks
- Store raw voucher codes
- Make destructive DB changes on activation/deactivation
- Hardcode store-specific assumptions
- Assume this VPS path exists outside local development docs
- Reimplement all of WooCommerce checkout logic

---

## Current Strategic Assessment

The plugin has crossed from prototype to structured MVP foundation. The core architecture is healthy and can support long-term expansion without a rewrite if discipline is maintained.

The highest future risk is not the core engine; it is admin complexity growth. As admin screens expand, the next architectural cleanup should likely include reusable table renderers, form handlers, notice handling, and possibly a gradual move toward `WP_List_Table` patterns.
