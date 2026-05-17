# Classic checkout certification

**Environment:** Local Docker WooCommerce + prior `biopentra.eu` evidence  
**Certification date:** 2026-05-17  
**Commit baseline:** `a012221` (browser QA beta release prep adds this doc)  
**Cart page:** ID 82 (`cart-2`) — `[woocommerce_cart]` shortcode  
**Checkout page:** ID 83 (`checkout-2`) — `[woocommerce_checkout]` shortcode  

**Legend:** Pass | Fail | Partial | Blocked | Not run

---

## Summary

| Area | Status |
|------|--------|
| **Engine / WP-CLI smokes** | **Pass** (checkout integrity, stacking, free shipping, etc.) |
| **Browser E2E checkout** | **Partial** — COD enabled locally; full browser pass not recorded in this doc run |
| **Production browser** | **Blocked** — BTCPay-only |

---

## Certification matrix

| Scenario | WP-CLI / smoke | Browser (classic) | Notes |
|----------|----------------|-------------------|--------|
| **Stacked fees** | **Pass** | **Not run** | `stacking-smoke.php` — dual fees, reversal, meta |
| **Scoped discounts** | **Pass** | **Not run** | `scoped-discount-smoke.php` |
| **Cheapest item BOGO** | **Pass** | **Not run** | `cheapest-item-smoke.php` |
| **Free shipping** | **Pass** | **Not run** | `free-shipping-smoke.php` |
| **Free gift** | **Partial** | **Not run** | Evaluator/builder pass; cart add needs browser |
| **Promotion code** | **Partial** | **Not run** | `manual-promotion-code-test.md`; live codes paused on biopentra |
| **Checkout recording** | **Pass** | **Partial** | `checkout-integrity-smoke.php`; browser order with COD pending |
| **Usage counts** | **Pass** | **Partial** | Smoke asserts increment/decrement; browser not re-run |
| **Code usage** | **Partial** | **Not run** | Code `usage_count` in smoke paths |
| **Reversal** | **Pass** | **Not run** | Integrity smoke + stacking reversal |
| **Restore** | **Partial** | **Not run** | Hook coverage; manual integrity doc |
| **Reports / CSV export** | **Partial** | **Partial** | `reports-smoke.php`; export button not downloaded in browser |
| **Diagnostics / repair** | **Partial** | **Partial** | UI pass on biopentra; dry-run apply not on production |

---

## WP-CLI evidence (2026-05-17)

```bash
cd /home/magpern/woocommerce
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/checkout-integrity-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/stacking-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-gift-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-shipping-smoke.php
```

| Script | Result |
|--------|--------|
| `checkout-integrity-smoke.php` | **Pass** |
| `stacking-smoke.php` | **Pass** |
| `free-gift-smoke.php` | **Partial** (CLI cart skipped) |
| `free-shipping-smoke.php` | **Pass** |

---

## Payment gateway (local)

| Gateway | Status |
|---------|--------|
| COD | **Enabled for QA** (`woocommerce_cod_settings[enabled]=yes` + cache flush) |
| BACS | Available, disabled |
| BTCPay | Enabled on site — use COD for QA checkout |

---

## Browser sign-off (fill before `0.2.0-beta.1` tag)

| Check | Tester | Date | Pass? |
|-------|--------|------|-------|
| Place COD order with automatic promotion | | | |
| Redemption visible in admin | | | |
| Cancel order → usage reversed | | | |
| CSV export downloaded | | | |

**Approver:** ___________________ **Date:** ___________

---

## Related docs

- [BROWSER_QA_RUNBOOK.md](BROWSER_QA_RUNBOOK.md)
- [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md)
- [RELEASE_EVIDENCE_0.2.0_BETA1.md](RELEASE_EVIDENCE_0.2.0_BETA1.md)
