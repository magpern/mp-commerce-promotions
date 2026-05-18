# Gift cards & store credit

**Schema:** `1.18.0` adds `mp_cp_gift_cards` and `mp_cp_gift_card_transactions`. **`1.19.0`** adds customer store credit wallets on the same tables — see [STORE_CREDIT.md](STORE_CREDIT.md).

Gift cards are **stored-value credit** with an append-only ledger. They are **not** promotion rules, promotion codes, or `PromotionEvaluator` actions.

## Merchant admin

**WooCommerce → Commerce Growth → Gift Cards & Store Credit**

- **Issue** — create a card with amount, **currency** (WooCommerce dropdown, store default), optional expiry/recipient/note.
- **Currency** — validated against WooCommerce currencies; optional `mp_cp_gift_card_allowed_currencies` filter restricts the dropdown.
- **Plain code shown once** — only `code_hash` and `code_last4` are stored.
- **List / detail** — balance, status, ledger, adjust (+/- with note), void.
- **Statuses:** `active`, `depleted`, `expired`, `voided`.

## Checkout MVP (storefront)

- Customer enters a gift card code on **cart** or **classic checkout** (simple form).
- Credit applies as a **negative cart fee** labeled `Store credit ****{last4}`.
- **Partial payment** — applied amount is `min(card balance, cart payable)`; remaining total is due via the payment gateway.
- Balance is **not** deducted during cart preview; ledger **redeem** runs when the order is processed (`woocommerce_checkout_order_processed` / `woocommerce_payment_complete`).
- Order meta: `_mp_cp_gift_card_redemptions`, `_mp_cp_gift_card_redemption_recorded`, `_mp_cp_gift_card_redemption_reversed`.
- **Reversal** on cancelled / failed / refunded restores balance once (idempotent).

## Reports & diagnostics

- **Reports** tab — liability, issued/redeemed/adjusted/voided totals, depleted/expired counts.
- **Diagnostics** — gift card integrity checks + preview/apply repair (mark zero-balance active as depleted, expired dates as expired).

## Gift cards from products

Merchants can mark **normal WooCommerce products** to sell gift cards; paid orders generate codes idempotently. See [GIFT_CARD_PRODUCTS.md](GIFT_CARD_PRODUCTS.md).

## Future work (not in this milestone)

- Recipient-entered email / scheduled delivery for product gift cards
- Custom WooCommerce gift card product type
- Customer store credit wallets (see [STORE_CREDIT.md](STORE_CREDIT.md)) — **done in 1.19.0**
- Dedicated Blocks UI (fee/session path should not fatal on Blocks checkout)
- REST/AJAX admin APIs

## Verification

```bash
composer run lint:php
composer run test
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-ledger-smoke.php
```
