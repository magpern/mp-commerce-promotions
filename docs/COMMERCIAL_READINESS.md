# Commercial readiness

**Schema:** 1.14.0 (unchanged for this milestone)  
**Plugin version:** 0.1.0 (early beta / MVP)

## Current maturity

Commerce Promotions is suitable for **staged rollouts and merchant pilots**. It is **not** production-certified, marketplace-certified, or accounting-grade without your own QA and legal review.

## What is complete

- Rule-driven promotions with planner orchestration, codes, redemptions, reports, diagnostics
- HPOS compatibility declared (`custom_order_tables`)
- Getting Started onboarding tab
- Settings governance (telemetry, CSV, simulations, gift/shipping actions, pricing explainability)
- Compatibility status panel (environment snapshot)
- Support bundle export (redacted JSON)
- Opt-in uninstall data deletion
- PHPUnit and WP-CLI smoke scripts for core workflows
- Production hardening — profiler, safe mode, degraded storefront mode, concurrency locks, retention cleanup, `scripts/release-audit.sh`

## What is not production-certified

- Cart/Checkout **Blocks** compatibility not declared
- Discounts remain **cart fees** (no catalog line-price mutation)
- Tax, profitability, and allocation figures are **heuristics**
- WP-Cron automation is **optional and off by default** (hourly maintenance + daily cleanup when enabled)
- PHPCS baseline not zero-violation (incremental cleanup ongoing)
- No wordpress.org release pipeline in this repo

## Known limitations

| Area | Limitation |
|------|------------|
| Storefront | Fee-based discounts; free gifts mutate cart lines only |
| Blocks checkout | Not declared compatible |
| Uninstall | Data retained unless explicit delete opt-in |
| i18n | POT placeholder; not fully extracted |
| REST/AJAX admin | Not implemented |
| High volume | 100+ active promotions increase planner work; use safe mode and retention cleanup |
| Cron | Disabled by default; enable only with monitoring |

## Compatibility status

See **Reports → Compatibility status** or **Diagnostics → Compatibility status** for live WooCommerce/WordPress/PHP versions, HPOS state, tax mode, and active gateways.

## Required before paid release

- [ ] Independent security review
- [ ] Block checkout QA and optional `cart_checkout_blocks` declaration
- [ ] Merchant-facing documentation and SLA
- [ ] Full i18n extraction and at least one locale
- [ ] PHPCS/CI gating policy agreed
- [ ] Backup/restore runbook for opt-in uninstall deletion

## Required before WordPress.org marketplace

- [ ] GPLv2 compliance audit across dependencies
- [ ] Trademark and naming review
- [ ] No trialware/phone-home patterns
- [ ] Stable tag and assets (banner, icons)
- [ ] Community support plan

## Related docs

- [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [manual-pricing-engine-test.md](manual-pricing-engine-test.md)
