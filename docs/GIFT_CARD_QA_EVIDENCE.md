# Gift card storefront QA evidence

**Date:** 2026-05-18  
**Commits:** `22dca02` (storefront polish), product E2E tooling (latest)  
**Test recipient:** postmaster@biopentra.eu  
**Environment:** Docker WooCommerce → https://www.biopentra.eu

## Summary

| Area | Result |
|------|--------|
| CLI smoke (`gift-card-customer-experience-smoke.php`) | **Pass** |
| CLI product setup (`gift-card-product-setup.php`) | **Pass** |
| CLI product E2E (`gift-card-product-e2e-smoke.php`) | **Pass** (22 assertions) |
| PHPUnit (risk-based) | **Pass** (+ `GiftCardQaProductSetupTest`) |
| PHP lint | **Pass** |
| Browser checkout (manual) | **Recommended** — product URL live; CLI covers purchase logic |

## Gift card product configured

| Field | Value |
|-------|--------|
| **Product ID** | **4375** |
| **URL** | https://www.biopentra.eu/product/commerce-growth-gift-card-qa/ |
| **SKU** | `mp-cg-gift-card-qa` |
| **Meta** | `_mp_cp_sells_gift_card=yes`, `product_price`, expiry **365** days, `recipient_email_and_message` |
| **Setup** | `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php` (idempotent) |

> Correct gift-card flag meta is **`_mp_cp_sells_gift_card`**, not `_mp_cp_is_gift_card`.

## Product purchase QA (CLI)

| Scenario | Channel | Status | Order ID | Gift card ID | Customer | Notes |
|----------|---------|--------|----------|--------------|----------|-------|
| Setup product marked | CLI | **pass** | — | — | — | Product **4375** |
| Send now purchase | CLI | **pass** | **4379** | **27** | recipient postmaster@biopentra.eu | Generated on `processing`; no `plain_code` in meta |
| Scheduled delivery | CLI | **pass** | **4381** | (after runner) | postmaster@biopentra.eu | Pending until runner; fulfilled once |
| Recipient email + message | CLI | **pass** | 4379 | 27 | — | Line meta + card `recipient_email` |
| Gift card email status | CLI | partial | 4379 | 27 | — | `wp_mail` not asserted in CLI; check inbox / Diagnostics |
| My Account received | CLI | **pass** | — | 27 | postmaster@biopentra.eu | `list_received()` includes card |
| Balance checker | CLI | **pass** | — | issued test | — | Masked lookup; no full code in JSON |
| Checkout redemption | CLI | **pass** | **4380** | **30** | — | Debit **7.50** from **20.00** |
| Reversal restores balance | CLI | **pass** | 4380 | 30 | — | Balance restored after `reverse_on_order_status` |
| Store credit grant | CLI | **pass** | — | wallet | **customer_id 2** | **5.00** granted; user created if missing |

## Product purchase QA (browser — manual checklist)

| Scenario | Channel | Status | Notes |
|----------|---------|--------|-------|
| Send now purchase | Browser | pending | Buy product **4375**, recipient postmaster@biopentra.eu, send now |
| Scheduled delivery | Browser | pending | Send on date; confirm no email before date |
| Recipient message | Browser | pending | Personal message visible in checkout + email |
| My Account received | Browser | pending | Log in as postmaster@biopentra.eu → Gift cards |
| Balance checker page | Browser | pending | `/gift-card-balance/` with code from email |
| Checkout redemption | Browser | pending | Apply code at cart; partial payment copy |
| Store credit wallet card | Browser | pending | My Account wallet shows balance after grant |

## Prior storefront QA (CLI slices)

| Scenario | Status | Notes |
|----------|--------|-------|
| Balance checker invalid / masked / disabled | pass | |
| Mail diagnostics | pass | 0 delivery failures at last run |
| My Account endpoint | pass | `gift-cards` |
| Admin issue / reissue / refund-to-credit UI | partial | Admin browser not re-walked |

## Tooling

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-customer-experience-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-storefront-qa-evidence.php
```

## Diagnostics

- **No gift card products** warning when catalog count is 0
- **WP_DEBUG** shows WP-CLI setup script hint (no auto-create in admin)

## Blocks

Fee/session path only; no custom Blocks UI (see [GIFT_CARD_CUSTOMER_EXPERIENCE.md](GIFT_CARD_CUSTOMER_EXPERIENCE.md)).

## Remaining limitations

- Browser email deliverability not verified in CI
- Scheduled cards not listed in My Account until generated
- No PDF/wallet, no custom product type, no Blocks redemption UI
- Full codes never stored in order meta; browser needs email for code entry tests

## Verification

```bash
composer run lint:php
composer run test -- --filter GiftCardQaProductSetupTest
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
```
