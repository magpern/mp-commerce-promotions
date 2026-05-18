# Gift card storefront QA evidence

**Date:** 2026-05-18  
**Commits:** `8952d90` (product E2E tooling), browser/pilot prep (latest)  
**Test recipient:** postmaster@biopentra.eu  
**QA product:** ID **4375** — https://www.biopentra.eu/product/commerce-growth-gift-card-qa/

## Summary

| Area | Result |
|------|--------|
| CLI product E2E smoke | **Pass** |
| CLI customer smoke | **Pass** (after balance page repair) |
| Browser product page | **Pass** (panel, preview, add to cart) |
| Browser checkout purchase | **Blocked** — payment gateway / manual order not run in this pass |
| Browser balance checker | **Pass** (page ID **4382** after `GiftCardCustomerDiagnostics::repair`) |
| Browser My Account / redemption | **Partial** — requires login + gift code from email |

---

## Browser QA (2026-05-18)

| # | Scenario | Status | Notes |
|---|----------|--------|-------|
| 1a | Product — gift card panel visible | **pass** | “About this gift card” / digital copy after deploy |
| 1b | Product — recipient mode understandable | **pass** | “Recipient details” / “At checkout” hint for `recipient_email_and_message` |
| 1c | Product — email preview | **pass** | `****SAMPLE`, amount 30,00 €, redeem instructions |
| 1d | Product — add to cart | **pass** | “Add to cart” on product **4375** |
| 2 | Checkout send-now (full) | **blocked** | Not completed in browser; CLI E2E covers generation (order **4379**, card **27**) |
| 3 | Checkout scheduled | **blocked** | CLI E2E order **4381**; browser date picker not exercised |
| 4a | Balance checker — valid code | **partial** | Form at `/gift-card-balance/`; valid code needs email (not entered in browser) |
| 4b | Balance checker — invalid code | **not run** | CLI: generic error verified |
| 4c | Balance checker — disabled | **not run** | CLI: shortcode “unavailable” when setting off |
| 5a | My Account — received cards | **partial** | CLI: card **27** for postmaster@biopentra.eu; browser login not run |
| 5b | My Account — store credit | **partial** | CLI: customer **2**, 5,00 grant; browser not run |
| 6 | Redemption / reversal | **partial** | CLI orders **4380** / card **30**; browser cart not run |

### Checkout send-now (CLI reference)

| Field | Value |
|-------|--------|
| Order ID | **4379** |
| Gift card ID | **27** |
| Recipient | postmaster@biopentra.eu |
| Message | stored on line item |
| Order meta `plain_code` | **absent** (verified) |
| Email delivery | **partial** — confirm inbox / SMTP manually |

### Scheduled delivery (CLI reference)

| Field | Value |
|-------|--------|
| Order ID | **4381** |
| Immediate generation | **no** |
| Pending row | **yes** |
| Runner fulfill | **pass** |

---

## CLI automated (regression)

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
```

Product **4375**, send_now card **27**, scheduled order **4381**, redeem card **30**, customer **2**.

---

## Pilot readiness

See [GIFT_CARD_PILOT_CHECKLIST.md](GIFT_CARD_PILOT_CHECKLIST.md).

Before pilot:

1. Configure SMTP and send a real test email.
2. Run Diagnostics → **Create balance page & flush endpoints** if `/gift-card-balance/` 404s.
3. Complete one browser checkout with COD/test gateway.

---

## Tooling & verification

```bash
composer run lint:php
composer run test -- --filter 'GiftCardProductAdminHelperTest|GiftCardQaProductSetupTest'
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
```
