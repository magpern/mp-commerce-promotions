# Gift card storefront QA evidence

**Date:** 2026-05-18  
**Commit:** storefront QA pass (post `74a9a2e` customer experience)  
**Test recipient:** postmaster@biopentra.eu  
**Environment:** Docker WooCommerce (biopentra.eu sync target)

## Summary

| Area | Result |
|------|--------|
| CLI smoke (`gift-card-customer-experience-smoke.php`) | **Pass** (22 assertions) |
| CLI QA collector (`gift-card-storefront-qa-evidence.php`) | **Pass** (automated slices) |
| PHPUnit | **Pass** (443 tests, 972 assertions) |
| PHP lint | **Pass** |
| Browser / checkout product flows | **Partial** — no gift card product flagged in catalog at QA time |

## Automated CLI scenarios

| Scenario | Status | IDs / notes |
|----------|--------|-------------|
| Balance checker invalid code | **pass** | Generic error: “We could not find a gift card with that code…” |
| Balance checker valid (masked) | **pass** | gift_card_id **20**, masked `****DZTR` |
| Rate limit key path | **pass** | `mp_cp_gc_balance_*` |
| Balance checker disabled setting | **pass** | Shortcode shows “unavailable” when option off |
| Mail diagnostics | **pass** | `wp_mail_likely_failing`: false, delivery_failed: 0 |
| My Account lists | **pass** | recipient email postmaster@biopentra.eu — received_count **7** |
| Checkout redemption preview (full) | **pass** | gift_card_id **21**, preview_amount 10.00 |
| Checkout redemption preview (partial) | **partial** | gift_card_id 20 — balance equals cart preview (30/30); use smaller cart in browser for true partial |
| Gift card product on catalog | **partial** | product_id **0** — no `_mp_cp_is_gift_card=yes` product at QA time |

Collect fresh JSON:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-storefront-qa-evidence.php
```

## Manual storefront scenarios

Record for browser/admin follow-up when a gift card product exists.

| Scenario | Status | Order ID | Gift card ID | Customer | Product ID | Email delivery | Notes |
|----------|--------|----------|--------------|----------|------------|----------------|-------|
| Manual admin issue | partial | — | CLI issue **20** | admin | — | n/a | Admin UI not re-walked in browser this pass |
| Product purchase — send now | partial | — | — | — | **0** | — | Requires gift card product + checkout |
| Product purchase — scheduled | partial | — | — | — | **0** | — | Card generated at scheduled date (Option A) |
| Recipient email + message | partial | — | — | postmaster@biopentra.eu | — | — | Use recipient fields at checkout |
| My Account gift cards | pass | — | received **7** | postmaster@biopentra.eu | — | — | Endpoint `gift-cards` |
| Balance checker page | pass | — | — | — | — | — | Shortcode + rate limit verified in CLI |
| Checkout redemption partial | partial | — | 20 | — | — | — | Preview logic OK; browser cart TBD |
| Checkout redemption full | pass | — | 21 | — | — | — | Preview covers full balance |
| Reversal restores balance | partial | — | — | — | — | — | Not re-run E2E this pass (ledger unchanged) |
| Reissue unused card | partial | — | — | — | — | — | Admin order action — browser TBD |
| Store credit grant/redeem | partial | — | wallet | customer TBD | — | — | `grant_credit` path not run (no WP user for test email) |
| Refund-to-credit MVP | partial | — | — | — | — | — | Admin MVP — browser TBD |

## Polish fixes (this pass)

- Single checkout panel (no triple render on cart totals hook)
- Fixed invalid `</motion.div>` closing tags in checkout / My Account
- Clearer balance checker, My Account empty states, delivery labels, store credit wallet card
- Checkout: combined GC + SC notice, estimated amount still due
- Admin: gift card email deliverability section + SMTP warning
- Support bundle: `gift_card_mail` summary (no secrets)

## Mail diagnostics

- `GiftCardMailDiagnostics` + transient on `wp_mail` failure
- Diagnostics warning: **Configure SMTP before selling gift cards.**
- Delivery failed count surfaced in delivery security section

## Blocks

Gift card / store credit uses **fee + session** path. No custom Blocks components; checkout should not fatal on Blocks. Dedicated Blocks UI is future work (see [GIFT_CARD_CUSTOMER_EXPERIENCE.md](GIFT_CARD_CUSTOMER_EXPERIENCE.md)).

## Remaining limitations

- No gift card product configured on store at QA time (product flows blocked)
- Scheduled purchases not listed in My Account until card is generated
- No PDF / mobile wallet
- No custom product type; normal Woo products only
- Refund-to-credit not in native Woo refund UI
- Blocks-specific redemption UI not built
- Full codes never stored persistently; one-time reveal at checkout session only

## Verification commands

```bash
composer run lint:php
composer run test
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-customer-experience-smoke.php
```
