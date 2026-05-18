# Commercial readiness

**Schema:** 1.17.0  
**Plugin version:** 0.3.0-pilot.1 (pilot — not GA)

## Current maturity

Commerce Promotions is suitable for **staged rollouts and merchant pilots**. It is **not** production-certified, marketplace-certified, or accounting-grade without your own QA and legal review.

## What is complete

- Rule-driven promotions with planner orchestration, codes, redemptions, reports, diagnostics
- HPOS compatibility declared (`custom_order_tables`)
- Getting Started onboarding tab (points to Campaign Builder first)
- **Campaign Builder** as default Promotions landing tab (guided goals → draft promotions) — **0.3.0-pilot.1**
- **Advanced Promotions** tab for expert mode (raw JSON, orchestration, codes, cart simulation)
- Pilot release doc: [PILOT_RELEASE_0.3.0_PILOT1.md](PILOT_RELEASE_0.3.0_PILOT1.md)
- Settings governance (telemetry, CSV, simulations, gift/shipping actions, pricing explainability)
- Compatibility status panel (environment snapshot)
- Support bundle export (redacted JSON)
- Opt-in uninstall data deletion
- PHPUnit and WP-CLI smoke scripts for core workflows
- Production hardening — profiler, safe mode, degraded storefront mode, concurrency locks, retention cleanup, `scripts/release-audit.sh`
- Ecosystem certification (1.17.0) — coupon matrix, tax/currency diagnostics, certification runs table, emergency ops, load harness, [COUPON_COMPATIBILITY.md](COUPON_COMPATIBILITY.md), [TAX_COMPATIBILITY.md](TAX_COMPATIBILITY.md), operations runbooks
- Beta certification docs — [BETA_READINESS.md](BETA_READINESS.md), blocks investigation, browser QA matrix, extracted POT

## What is not production-certified

- Cart/Checkout **Blocks** declared for fee path; line mode UI still partial in blocks
- **Line_item / hybrid** discount modes are **experimental** — fee-based remains default; complete [manual-line-discount-engine-test.md](manual-line-discount-engine-test.md) before pilot
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
- [x] Block checkout QA **passed** — [BLOCKS_QA_EVIDENCE_2026-05-18.md](BLOCKS_QA_EVIDENCE_2026-05-18.md); `cart_checkout_blocks` declared (line unit display still partial)
- [ ] Fresh browser certification records (<30d) for classic, blocks, line mode, coupon coexistence (Diagnostics)
- [ ] Merchant-facing documentation and SLA (include [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md))
- [ ] Full i18n extraction and at least one locale
- [ ] PHPCS gating enabled in CI (currently **informational only** — see [BETA_READINESS.md](BETA_READINESS.md))
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
