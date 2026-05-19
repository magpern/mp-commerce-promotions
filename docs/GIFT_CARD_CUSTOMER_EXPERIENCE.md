# Gift card customer experience

Customer-facing layer on top of the ledger-backed gift card system. **No change** to code storage, hashing, or generation rules.

## Balance checker

- Shortcode: `[mp_cp_gift_card_balance]`
- Optional page created on activation: `/gift-card-balance/`
- Shows masked code, balance, currency, status, expiry
- Rate-limited by IP (transient)
- Disable in **Settings → Gift cards & store credit**

## My Account

Endpoint: **Gift cards** (`/my-account/gift-cards/`)

- **Purchased by me** — cards tied to your customer account as purchaser (`purchaser_customer_id`)
- **Sent to me** — cards whose `recipient_email` matches your account email (no account required for recipients to redeem)
- **Store credit wallet** — balance and recent ledger transactions

Gift cards are **code-owned**, not account-owned. Recipients redeem with the code from email. If they later register with the same email, received cards appear under **Sent to me**.

Unused purchased cards can be **sent to another recipient** (voids the old code, issues a new code emailed to the new address; the previous code is never shown in My Account). Only **fully unused** active cards qualify (balance equals initial amount). Partially used cards cannot be transferred.

Transfer emails use the same delivery mailer and sender settings as purchase delivery ([GIFT_CARD_EMAILS.md](GIFT_CARD_EMAILS.md)); configure SMTP before pilot.

Masked code, balance, status, expiry, and delivery status are shown. One-time “copy code” if the customer applied a gift card in the same checkout session.

### Admin transfer

**Gift Cards → View card → Reissue to new recipient** — same unused-only rules; note required. See `scripts/gift-card-transfer-smoke.php`.

## Product page

Gift card products show a purchase panel: delivery, redemption, partial payment, recipient help, collapsible **email preview** (masked sample code only).

Mark products with `_mp_cp_sells_gift_card=yes` (see [GIFT_CARD_PRODUCTS.md](GIFT_CARD_PRODUCTS.md)). For QA, run `scripts/gift-card-product-setup.php` to create SKU `mp-cg-gift-card-qa`. Test recipient: `postmaster@biopentra.eu`.

Pilot: [GIFT_CARD_PILOT_CHECKLIST.md](GIFT_CARD_PILOT_CHECKLIST.md). If `/gift-card-balance/` 404s, run **Diagnostics → Create balance page & flush endpoints**.

## Checkout redemption

On **cart**, a subtle link disclosure (**Apply gift card or store credit**) renders after the cart table via `woocommerce_after_cart_table` (outside the coupon flex row, still inside the cart form). Clicking expands a compact form (gift card code + store credit when logged in with balance). Collapsed copy shows wallet **Available: {amount}** when relevant; when applied, **Gift card ****{last4} applied · Change/remove** (or store credit applied). On **checkout**, the compact bordered **Gift card or store credit** accordion remains before the checkout form. Both UIs expand when a gift card or store credit is applied, when WooCommerce success/error notices are present, or when a logged-in customer has store credit available. Expanded gift card input uses a coupon-proportioned flex row (input left, **Apply gift card** right; stacks on narrow screens). Ledger, session, and fee behavior are unchanged.

### WooCommerce Blocks

Gift card and store credit checkout adjustments use the **fee/session path** (same as classic checkout). There are **no custom Blocks components** yet — redemption UI is classic-oriented. Blocks checkout should not fatal; dedicated Blocks UI is future work.

## Email deliverability

**Diagnostics → Gift card email deliverability** surfaces delivery failures, last `wp_mail` failure transient, and a warning to configure SMTP before selling gift cards. Support bundle exports a redacted `gift_card_mail` summary (no secrets).

## Settings

See **Gift Cards & Store Credit → Settings** for templates, branding, balance checker, My Account, scheduled cron, sender, and support footer.

## Security

- Full codes are never stored in order meta or the database after delivery
- Balance checker and My Account show `****` + last4 only
- Codes are not written to logs or audit entries
