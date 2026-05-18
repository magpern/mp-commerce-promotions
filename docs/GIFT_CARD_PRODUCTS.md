# Gift cards sold via WooCommerce products

**Schema:** unchanged (`1.19.0`). Gift cards generated from product purchases use the same `mp_cp_gift_cards` ledger as manual issue and store credit wallets.

## Overview

Merchants sell gift cards using **normal WooCommerce simple or variable products** — there is **no custom product type** yet. When a paid order contains a marked gift-card line, the plugin generates one gift card per unit (quantity), idempotently.

## Product setup

On the product edit screen (General tab for simple products; variation rows for variations):

| Setting | Meta key | Notes |
|--------|----------|--------|
| This product sells a gift card | `_mp_cp_sells_gift_card` | `yes` / `no` |
| Amount mode | `_mp_cp_gift_card_amount_mode` | `product_price` or `fixed_amount` |
| Fixed amount | `_mp_cp_gift_card_fixed_amount` | Used when mode is `fixed_amount` |
| Expiry (days) | `_mp_cp_gift_card_expiry_days` | Optional; expiry = paid date + N days |
| Recipient | `_mp_cp_gift_card_recipient_mode` | `purchaser_only` only (billing email) |

**Amount modes**

- **Same as product price** — per-unit amount = line subtotal ÷ quantity.
- **Fixed amount** — each generated card uses the configured fixed amount (ignores line price).

## Generation (paid orders)

Hooks: `woocommerce_order_status_processing`, `woocommerce_order_status_completed` (priority 20).

For each qualifying line item and each unit index `0 … qty-1`:

- Issue gift card via ledger (`issue_from_order`).
- Store `created_order_id`, `purchaser_customer_id`, `recipient_email` (billing), currency, optional `expires_at`.
- Record rows in order meta `_mp_cp_generated_gift_cards` (JSON: `gift_card_id`, `order_item_id`, `unit_index`, optional `plain_code`).
- Mark `_mp_cp_gift_cards_generated` = `yes` when complete.
- Audit: `gift_card.generated_from_order`.

**Idempotency:** Slots keyed by `order_item_id` + `unit_index`; completed orders skip re-issue.

## Delivery MVP

- **No** scheduled delivery, recipient form, or branded templates.
- When **Settings → Gift cards → Send gift card codes by email** is enabled (default on), a plain `wp_mail()` is sent to the **billing email** with each new code, amount, and expiry.
- Order admin meta box shows last4, balance, status, link to Gift Cards tab; full code only if still present in order meta (`plain_code`).

## Cancellation / refund

On `cancelled`, `refunded`, or `failed`:

- If card balance equals initial amount (unused): **void** card + ledger void transaction.
- If partially used: **no** auto-void; private order note warns manual review.
- Idempotent via `_mp_cp_gift_cards_reversal_handled`.

## Admin & reporting

- **Gift Cards** tab — source column (Manual / Product order #N / Store credit wallet); filters by origin and order ID.
- **Reports** — gift cards sold from products (count), product-generated liability, product-generated vs manual issued totals.
- **Diagnostics** — paid orders missing generation, cards missing `created_order_id`, cancelled orders with active unused cards; repair can generate missing cards or run reversal voids.

## Not included

- Custom WooCommerce product type
- Promotion engine / `PromotionEvaluator` coupling
- Scheduled delivery or separate recipient email at checkout
- REST/AJAX product APIs

## Verification

```bash
composer run lint:php
composer run test
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-smoke.php
```

See also [GIFT_CARDS_STORE_CREDIT.md](GIFT_CARDS_STORE_CREDIT.md), [STORE_CREDIT.md](STORE_CREDIT.md), [MERCHANT_WORKFLOWS.md](MERCHANT_WORKFLOWS.md).
