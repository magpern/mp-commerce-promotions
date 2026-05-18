# Gift Cards & Store Credit — pilot checklist

Use this before enabling gift card sales for real customers.

## Prerequisites

- [ ] **Configure SMTP** (or reliable transactional email). Verify a test message delivers to a real inbox.
- [ ] **Gift card email sender** — Settings → **Default** mode unless SMTP authorizes a custom From address ([GIFT_CARD_EMAILS.md](GIFT_CARD_EMAILS.md)).
- [ ] **Do not go live** until you have confirmed at least one gift card email with the real code.
- [ ] WooCommerce **Commerce Growth** plugin active; schema **1.19.0+**.

## Product setup

- [ ] Create or mark a **virtual** simple product: **Product data → This product sells a gift card**.
- [ ] Set amount mode (product price or fixed), expiry days, and recipient mode.
- [ ] QA shortcut (staging):  
  `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php`  
  (SKU `mp-cg-gift-card-qa`, test recipient `postmaster@biopentra.eu`)

## Functional tests

- [ ] **Send now** — checkout with recipient email; order paid; card generated; email received; order meta has **no** `plain_code`.
- [ ] **Scheduled delivery** — future date; no card at payment; cron/admin runner fulfills on date; status recorded.
- [ ] **Balance checker** — page/shortcode shows masked balance; invalid code shows generic error.
- [ ] **My Account → Gift cards** — purchased/received lists; store credit wallet if used.
- [ ] **Recipient explanation** — copy states code-owned cards, email redemption, and that matching account email shows cards under **Sent to me** (recipients do not need accounts).
- [ ] **Customer transfer (unused only)** — on a fully unused active purchased card, **Send to another recipient**; new recipient email; optional name/message; old code voided; new code emailed; no full code shown in My Account or admin after reload.
- [ ] **Admin reissue** — **Gift Cards → View card → Reissue to new recipient** with required note; same unused-only rules; transfer link visible on card detail.
- [ ] **Transfer blocked when partially used** — card with balance &lt; initial amount cannot be transferred (customer or admin).
- [ ] **SMTP for transfer** — transfer emails use the same gift card mailer/sender settings as purchase delivery; confirm inbox delivery with a verified-domain test address (not `example.com`).
- [ ] **Redemption** — apply code at cart/checkout; partial payment; order completes; ledger debited.
- [ ] **Cancellation / reversal** — cancel order with unused card (void) or reverse redemption (balance restored).
- [ ] **Liability report** — Commerce Growth → Reports; gift card totals plausible.
- [ ] **Support / reissue** — failed delivery visible in order admin; reissue unused card from order if needed.

## Diagnostics

- [ ] **Diagnostics → Gift card products** — no missing generation on paid orders.
- [ ] **Diagnostics → Gift card email deliverability** — no pilot warning (“products active but email may fail”); sender mode **default** or authorized **custom**; run **Send test gift card email** (`****TEST` only).
- [ ] **Support bundle** export includes `gift_card_mail` summary (no secrets).

## Known pilot limits

- No PDF or mobile wallet passes.
- No custom WooCommerce product type (normal products only).
- Blocks checkout uses fee/session path — no dedicated Blocks redemption UI.
- Scheduled cards appear in My Account after generation.
- Refund-to-store-credit is admin MVP only (not native Woo refund UI).

## Verification commands

```bash
composer run lint:php
composer run test -- --filter GiftCardTransfer
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-mail-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-transfer-smoke.php
```

See also [GIFT_CARD_QA_EVIDENCE.md](GIFT_CARD_QA_EVIDENCE.md), [GIFT_CARD_PRODUCTS.md](GIFT_CARD_PRODUCTS.md), [GIFT_CARD_EMAILS.md](GIFT_CARD_EMAILS.md).
