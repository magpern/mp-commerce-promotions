# Commerce Growth production data reset

**Date:** 2026-05-19  
**Site:** https://www.biopentra.eu/  
**Plugin:** mp-commerce-promotions `0.3.0-pilot.3`  
**Schema:** `1.19.0` (unchanged)

## Summary

Test/demo/QA data for Commerce Growth was audited, backed up, and removed in two apply passes. Real WooCommerce catalog orders, customers, products, plugin settings, email/SEO configuration, and schema were preserved.

## Backups

Full-table exports (JSON; `code_hash` redacted) and options snapshots were written under:

| Pass | Directory |
|------|-----------|
| Audit (pre-apply) | `wp-content/uploads/mp-cp-cleanup-backup-20260519-141252/` |
| Apply #1 | `wp-content/uploads/mp-cp-cleanup-backup-20260519-141321/` |
| Apply #2 (transfer smoke) | `wp-content/uploads/mp-cp-cleanup-backup-20260519-141641/` |

Each directory contains `cleanup-report.md`, `gift_cards.json`, `gift_card_transactions.json`, `gift_cards_test_subset.json`, and related exports.

## Pre-cleanup audit (approximate)

| Area | Count |
|------|------:|
| Gift cards (total) | 107 |
| Gift cards (classified test) | 86 → 94 after pass 2 |
| Gift card transactions (test) | 120 → 131 |
| Promotions (test, non-active) | 281 |
| Redemptions (related) | 36 |
| Audit log rows (related) | 1,582 |
| WooCommerce smoke orders trashed | 27 |
| WooCommerce QA products trashed | 3 |
| Store credit QA wallets | 2 |

## Deleted (cumulative)

| Entity | Rows |
|--------|-----:|
| Gift cards | 94 |
| Gift card transactions | 131 |
| Promotions (+ codes/batches/snapshots) | 281 + 41 related |
| Redemptions | 36 |
| Audit log | 1,582 |
| WooCommerce orders (smoke/QA → trash) | 27 |
| WooCommerce products (QA SKUs) | 3 |
| Test-only options | 5 |
| Automation / simulation telemetry | 4 |
| Planner telemetry (QA-linked) | 62 |

## Tables affected

- `wp_mp_cp_gift_cards`
- `wp_mp_cp_gift_card_transactions`
- `wp_mp_cp_promotions`
- `wp_mp_cp_redemptions`
- `wp_mp_cp_audit_log`
- `wp_mp_cp_promotion_codes`
- `wp_mp_cp_code_batches`
- `wp_mp_cp_promotion_snapshots`
- `wp_mp_cp_automation_runs` (truncated)
- `wp_mp_cp_simulation_scenarios` (truncated)
- `wp_mp_cp_planner_telemetry` (partial)
- WooCommerce `wp_posts` / order items (QA orders → trash; QA products → trash)
- Options: `mp_cp_block_qa_*`, `mp_cp_browser_qa_*`, `mp_cp_gift_card_test_email_last`, report transients

**Not modified:** schema version, merchant settings, email template/sender config, SEO options, active promotions (7).

## Preserved production data

| Area | After cleanup |
|------|----------------|
| Gift cards remaining | 13 (12 gift_card + 1 store_credit wallet #95) |
| Promotions (total / active) | 528 / 7 |
| Store credit wallet (customer 1, “he is nice”) | Yes (#95) |
| Real WC orders/customers/products | Untouched except QA smoke orders trashed |
| Plugin settings & templates | Yes |

### Manual review (optional)

These rows were **not** auto-classified (no QA email/label/note match) but may be early manual/checkout smoke:

| ID | Notes |
|----|--------|
| 18–36 | No recipient; checkout redeem/refund pattern |
| 38–39 | `magnus.pernemark@gmail.com` manual issuance |
| 64–65, 70–71 | Small balances; transfer-suite companions without tagged notes |

Remove only if you confirm they are not production liabilities.

## Verification

- Re-audit: `gift_cards_test: 0`, `promotions_test: 0`, `transactions_test: 0`
- `pilot-release-smoke.php` — passed (readonly, no demo content)
- `commerce-growth-navigation-smoke.php` — passed
- Smoke QA orders: status `trash` (e.g. order 4376)

## How to run again (idempotent)

```bash
cd /path/to/woocommerce

# Audit + backup only
MP_CP_ALLOW_LIVE_QA=1 ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-data-reset.php

# Apply (destructive)
MP_CP_PRODUCTION_DATA_RESET=1 MP_CP_PRODUCTION_DATA_RESET_APPLY=1 MP_CP_ALLOW_LIVE_QA=1 \
  ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-data-reset.php
```

Docker:

```bash
MP_CP_ALLOW_LIVE_QA=1 docker compose run --rm --no-deps -e MP_CP_ALLOW_LIVE_QA=1 wpcli \
  eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-data-reset.php
```

## Safeguards (Phase 5)

- `QaRuntimeGuard`: production-like hosts block persistent QA unless `MP_CP_ALLOW_LIVE_QA=1`; persistent scripts default to **dry-run** unless `MP_CP_QA_APPLY=1`.
- `production-data-reset.php`: readonly by default; apply requires `MP_CP_PRODUCTION_DATA_RESET=1`, `MP_CP_PRODUCTION_DATA_RESET_APPLY=1`, and `MP_CP_ALLOW_LIVE_QA=1`.
- QA scripts use `QaEmailSuppression` unless `MP_CP_ALLOW_QA_EMAILS=1`.
- No automatic QA product/gift-card creation on production without explicit env flags.

See also [QA_SCRIPT_SAFETY.md](QA_SCRIPT_SAFETY.md).
