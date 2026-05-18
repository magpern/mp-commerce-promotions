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

- Purchased cards (by `purchaser_customer_id`)
- Received cards (by account email / `recipient_email`)
- Masked code, balance, status, expiry, delivery date
- Store credit wallet balance and recent transactions
- One-time “copy code” if the customer applied a gift card in the same session at checkout

## Product page

Gift card products show a purchase panel: delivery, redemption, partial payment, recipient help, collapsible **email preview** (masked sample code only).

## Checkout redemption

Single **Gift card or store credit** section on cart and checkout (classic + cart totals hook for Blocks compatibility). Partial payment explained; estimated remaining balance after apply.

## Settings

See **Gift cards & store credit** in Commerce Growth settings: templates, branding, balance checker, My Account, scheduled cron, sender, support footer.

## Security

- Full codes are never stored in order meta or the database after delivery
- Balance checker and My Account show `****` + last4 only
- Codes are not written to logs or audit entries
