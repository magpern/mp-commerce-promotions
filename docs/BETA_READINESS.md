# Beta readiness certification

**Plugin version:** 0.1.0 (release candidate planning: **0.2.0-beta.1** or **0.2.0**)  
**Schema:** 1.14.0  
**Certification date:** 2026-05-17  
**Commit baseline:** post `14de6e8` + beta certification milestone

## What is beta-ready

- Core promotion engine: conditions, actions, planner, codes, redemptions, HPOS
- Production hardening: profiler, safe mode, degraded storefront, optional cron, retention cleanup
- Admin: Getting Started, Settings gates, Reports production dashboard, Diagnostics repair
- Automated checks: PHPUnit, release audit, multiple WP-CLI smoke scripts
- **Real POT** at `languages/mp-commerce-promotions.pot` (WP-CLI `wp i18n make-pot`)
- CI: syntax lint, PHPUnit, build zip; **PHPCS runs with continue-on-error** (visibility, not gating)

## What is not certified

| Area | Status |
|------|--------|
| Cart/Checkout Blocks | **Not declared** — **partial** QA 2026-05-18 ([BLOCKS_QA_EVIDENCE_2026-05-18.md](BLOCKS_QA_EVIDENCE_2026-05-18.md)); server hooks OK; block UI blocked on Blocksy |
| Marketplace / wordpress.org | Not submitted |
| Accounting / tax correctness | Heuristic only |
| Full browser checkout on production | Partial (BTCPay blocker on reference site) |
| PHPCS zero violations | Not a goal for this milestone |
| Translated locales | POT only; no `.po` / `.mo` shipped |

## Compatibility declarations

| Feature | Declared | Evidence |
|---------|----------|----------|
| HPOS (`custom_order_tables`) | **Yes** | `WooCompatibility`, HPOS order meta |
| Cart/Checkout Blocks | **No** | Shortcode cart on test env; block E2E not run |

## PHPCS status

- **Policy:** Not gating merges; CI runs **PHPCS advisory** (`continue-on-error: true`, `|| true`). See [PHPCS_BASELINE.md](PHPCS_BASELINE.md).
- **Target paths** (Service + key Admin/Woo): PHPCBF applied on staging; see CHANGELOG / release notes for counts
- **Goal:** Trend down errors/warnings per milestone; enable gating when target subset is stable

Regenerate baseline locally:

```bash
composer install
composer run lint:phpcs
```

## i18n status

- **POT:** Generated via `./wp i18n make-pot` (from WooCommerce project root, plugin path as source)
- **Text domain:** `mp-commerce-promotions`
- **Locales:** None bundled; merchants may add `languages/mp-commerce-promotions-{locale}.po`

Regenerate:

```bash
cd /path/to/woocommerce
./wp i18n make-pot wp-content/plugins/mp-commerce-promotions \
  wp-content/plugins/mp-commerce-promotions/languages/mp-commerce-promotions.pot \
  --domain=mp-commerce-promotions \
  --exclude=vendor,tests,node_modules
```

## Required manual QA before external beta users

1. Classic cart: stacked fees, subtotal cap, exclusive vs stackable
2. Promotion code in coupon field → checkout → redemption row
3. Free gift add/remove on cart recalc
4. Order cancel/refund → reversal
5. Reports CSV export (no raw codes)
6. Settings: safe mode, telemetry pause, cron off by default
7. If using **blocks:** complete [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md) checklist first

See [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md) and [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md).

## Known limitations

- Fee-based discounts (not line-item catalog prices)
- 100+ active promotions increase planner cost
- Partial refunds not supported for reversal
- Generated codes show-once
- Block checkout compatibility unverified

## Support policy (draft)

- **Channel:** GitHub issues on `magpern/mp-commerce-promotions` (best effort)
- **Scope:** Bug reports with steps, WP/WC versions, Compatibility status export
- **Out of scope:** Custom promotion logic, theme-specific CSS, block checkout without block QA pass
- **Data:** Support bundle from Diagnostics (redacted JSON); no raw promotion codes in exports

## Emergency rollback checklist

1. **Safe mode:** WooCommerce → Promotions → Settings → enable Safe mode (disables automatic promotions)
2. **Cart discounts off:** Disable “Enable cart discounts” in Settings
3. **Automation stop:** Enable “Automation emergency stop”
4. **Plugin deactivate:** WooCommerce → Plugins → Deactivate (data retained by default)
5. **Verify:** Cart totals return to pre-promotion behavior; no new fees on recalc
6. **If uninstall needed:** Do **not** enable delete-on-uninstall unless intentional data wipe

## Release version planning

| Item | Value |
|------|--------|
| **Next intended tag** | `0.2.0-beta.1` (public beta) or `0.2.0` (if block QA + merchant pilot complete) |
| **Version bump rule** | Header + `MP_COMMERCE_PROMOTIONS_VERSION` + readme stable tag + POT `Project-Id-Version` |
| **Release criteria** | CI green (lint/test/zip); release-audit pass; beta-readiness-smoke pass; BETA_READINESS reviewed |
| **Tag criteria** | CHANGELOG section for version; git tag `v0.2.0-beta.1`; attach build zip |

Do **not** bump plugin version in code until product owner approves tag.

## Related documents

- [COMMERCIAL_READINESS.md](COMMERCIAL_READINESS.md)
- [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md)
- [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md)
