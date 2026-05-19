# Gift card & store credit QA evidence

**Last stabilization pass:** 2026-05-18  
**Commits:** `3207f35` (email template settings), `7f3b173` (transfer), stabilization commit  
**Test recipient:** postmaster@biopentra.eu (verified domain; do not use `example.com` with real SMTP)  
**QA product:** ID **4375** — Commerce Growth gift card QA SKU

## Summary

| Area | Result |
|------|--------|
| Module smoke (`gift-card-module-smoke.php`) | **Pass** (CLI) |
| Product E2E smoke | **Pass** |
| Transfer smoke | **Pass** |
| Mail / email settings smoke | **Pass** |
| Browser checkout purchase | **Blocked** — payment gateway not exercised in browser |
| Browser My Account / redemption | **Partial** — CLI covers flows; browser login optional |

---

## Browser & email QA matrix

| # | Scenario | Status | IDs / evidence | Notes |
|---|----------|--------|----------------|-------|
| 1 | Manual admin issue + email | **partial** | CLI manual issue + mail smoke | Browser: Gift Cards → Issue with recipient; confirm inbox |
| 2 | Product purchase send-now | **partial** | Order **4379**, card **27** | CLI E2E; recipient postmaster@biopentra.eu; no `plain_code` in meta |
| 3 | Product purchase scheduled | **partial** | Order **4381** | Pending → runner fulfill; CLI verified |
| 4 | Recipient message on line item | **pass** | Order **4379** | Message stored on line item meta |
| 5 | Customer transfer (unused) | **pass** | CLI transfer + module smoke | Void old, email new; link recorded |
| 6 | Admin reissue / transfer | **pass** | CLI smoke | Note required; unused only |
| 7 | Transfer blocked (partial/depleted) | **pass** | CLI + unit tests | Partial redeem and depleted blocked |
| 8 | My Account — purchased / sent / wallet | **partial** | Customer **2**, card **27** | CLI list_received; browser login not run |
| 9 | Recipient explanation copy | **pass** | My Account UI | Code-owned; email redeem; **Sent to me** |
| 10 | Balance checker | **partial** | Page **4382** | CLI valid/invalid; browser form not submitted |
| 11 | Balance checker disabled | **pass** | CLI shortcode | Shows unavailable when setting off |
| 12 | Checkout redemption | **partial** | Order **4380**, card **30** | CLI redeem + reversal |
| 13 | Redeem voided / expired | **pass** | Unit + module smoke | Specific error messages |
| 14 | Currency mismatch | **pass** | Unit test | Blocks apply when card currency ≠ cart |
| 15 | Gift card + store credit both applied | **pass** | Checkout UI copy | Combined notice when both active |
| 16 | Store credit grant / redeem | **pass** | CLI E2E + module smoke | Wallet grant; checkout path in E2E |
| 17 | Refund-to-credit MVP | **partial** | Admin only | Not native Woo refund UI |
| 18 | Email template preview | **pass** | Settings | `****SAMPLE` only |
| 19 | Send test gift card email | **pass** | Settings + Diagnostics | `****TEST` only |
| 20 | WooCommerce email style mode | **pass** | Settings + mail smoke | Falls back if WC mailer unavailable |
| 21 | Mail delivery disabled setting | **pass** | Module smoke | Returns disabled status |
| 22 | Scheduled on cancelled order | **pass** | `GiftCardOrderReversal` + scheduled repair | Pending rows marked cancelled |
| 23 | Cron disabled + pending scheduled | **partial** | Settings flag | Manual Diagnostics runner still works |

### Delivery reference (send-now CLI)

| Field | Value |
|-------|--------|
| Order ID | 4379 |
| Gift card ID | 27 |
| Recipient | postmaster@biopentra.eu |
| Order meta `plain_code` | **absent** |
| Email delivery | **partial** — confirm inbox / SMTP manually |

---

## CLI verification (recommended)

```bash
composer run lint:php
composer run test -- --filter 'GiftCard|StoreCredit'
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-module-smoke.php
```

Optional deep checks:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-mail-smoke.php
```

---

## Pilot blockers (manual)

1. SMTP verified with real inbox (not `example.com`).
2. One browser send-now purchase with payment gateway.
3. One scheduled delivery confirmed on due date.
4. One browser redemption at checkout.
5. One order cancellation / reversal observed in admin.

See [GIFT_CARD_PILOT_CHECKLIST.md](GIFT_CARD_PILOT_CHECKLIST.md).
