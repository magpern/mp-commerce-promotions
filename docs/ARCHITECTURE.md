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
1.4.0
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
PromotionEvaluator
RuleTypes
RuleRegistry
ConditionInterface
ActionInterface
```

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
```

`logged_in` passes when `EvaluationContext::get_customer_id()` is a positive integer.

`first_order` passes when context metadata `has_previous_orders` is explicitly `false`. The key is **not** set by `CartContextBuilder` yet; WooCommerce order-history enrichment is future work. Until then, use raw JSON rules and supply metadata in integrations/tests.

### Current Actions

```text
percentage_discount
fixed_amount_discount
```

### Engine Rules

- Conditions return pass/fail results.
- All conditions must currently pass.
- Actions return previews before WooCommerce application.
- Only the first supported action is currently applied in the MVP.
- Unknown condition/action types should fail safely.
- Evaluation should not mutate cart/order/database state.

---

## WooCommerce Integration

WooCommerce integration is isolated under:

```text
src/Woo/
```

Important classes:

```text
WooCommerceBridge
CartContextBuilder
CartPromotionApplier
OrderPromotionRecorder
PromotionCodeCouponBridge
```

### Current WooCommerce Behavior

- Active automatic promotions can apply through a negative cart fee.
- Manual promotion codes are entered through the standard WooCommerce coupon field.
- Virtual WooCommerce coupon data is used for known promotion codes.
- No native WooCommerce coupon posts are created.
- Checkout records promotion usage to custom tables and order meta.
- Reversal hooks handle cancelled, failed, refunded, trashed, and deleted orders where possible.

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
- Only first eligible promotion applies
- Only first supported action is applied
- No stackability/conflict engine yet
- No BOGO/free product logic yet
- No free shipping action yet
- No partial refund proportional reversal
- No customer segmentation yet
- No first-order/customer-role/country restrictions yet
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
