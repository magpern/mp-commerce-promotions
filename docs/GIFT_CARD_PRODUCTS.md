# Gift cards sold via WooCommerce products

**Schema:** unchanged (`1.19.0`). Gift cards generated from product purchases use the same `mp_cp_gift_cards` ledger as manual issue and store credit wallets.

## Overview

Merchants sell gift cards using **normal WooCommerce simple or variable products** — there is **no custom product type**. When a paid order contains a marked gift-card line, the plugin issues cards per unit (quantity), idempotently.

## Product setup

### Manual (product edit screen)

On the product edit screen (General tab for simple products; variation rows for variations):

| Setting | Meta key | Notes |
|--------|----------|--------|
| This product sells a gift card | `_mp_cp_sells_gift_card` | `yes` / `no` (not `_mp_cp_is_gift_card`) |
| Amount mode | `_mp_cp_gift_card_amount_mode` | `product_price`, `fixed_amount`, or `customer_amount` |
| Fixed amount | `_mp_cp_gift_card_fixed_amount` | Used when mode is `fixed_amount` |
| Minimum amount | `_mp_cp_gift_card_min_amount` | Required when mode is `customer_amount` |
| Maximum amount | `_mp_cp_gift_card_max_amount` | Optional cap for `customer_amount` |
| Suggested amounts | `_mp_cp_gift_card_suggested_amounts` | Comma-separated quick picks (e.g. `25,50,100`) |
| Default amount | `_mp_cp_gift_card_default_amount` | Optional prefill on the product page |
| Expiry (days) | `_mp_cp_gift_card_expiry_days` | Optional; expiry = paid date + N days |
| Recipient mode | `_mp_cp_gift_card_recipient_mode` | See below |

### Recipient modes

| Mode | Checkout fields | Delivery email |
|------|-----------------|----------------|
| `purchaser_only` (default) | None — billing email only | Billing email, send immediately |
| `recipient_email` | Recipient email, optional name, delivery timing | Recipient (or billing if empty) |
| `recipient_email_and_message` | Above + optional personal message (length capped) | Same, message included in plain email |

Recipient fields appear under **Gift card delivery** at checkout (one field group per gift-card line that allows recipients). Quantity &gt; 1 uses the same recipient data for every card on that line (MVP).

### Amount modes

| Mode | Customer experience | Generated value |
|------|---------------------|-----------------|
| `product_price` | Normal product price | WooCommerce line subtotal ÷ quantity |
| `fixed_amount` | Normal product price (set to fixed value) | Configured fixed amount |
| `customer_amount` | **Gift card amount** field on product page; loop shows **Choose amount** or **From {min}**; loop button **Select amount** | Amount chosen at add to cart (stored on line item meta `_mp_cp_gift_card_amount`) |

For `customer_amount`, the WooCommerce product price may be empty — the cart line price is set from the customer’s chosen amount. Quantity &gt; 1 issues multiple cards, each for the chosen per-unit amount.

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-customer-amount-smoke.php
```

### QA demo product (WP-CLI, idempotent)

Creates or updates a virtual product for automated and browser QA:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
```

| Field | Value |
|-------|--------|
| Name | Commerce Growth Gift Card QA |
| SKU | `mp-cg-gift-card-qa` |
| Price | 30 (amount mode `product_price`) |
| Expiry | 365 days |
| Recipient mode | `recipient_email_and_message` |

**Test recipient (QA):** `postmaster@biopentra.eu`

End-to-end CLI verification (after setup):

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
```

See [GIFT_CARD_QA_EVIDENCE.md](GIFT_CARD_QA_EVIDENCE.md).

### Delivery timing

- **Send now** — card is generated and emailed when the order reaches processing/completed.
- **Send on date** — no card is generated at payment. A pending row is stored on the order; an hourly WP-Cron job (and Diagnostics / order admin actions) generates and emails the card on or after the chosen date (YYYY-MM-DD). Scheduled date must be today or later, at most one year ahead (filterable).

**Security:** Full gift card codes are **never** stored in order meta, logs, or the database after email. Scheduled sends use **Option A**: generation happens at delivery time only.

## Generation (paid orders)

Hooks: `woocommerce_order_status_processing`, `woocommerce_order_status_completed` (priority 20).

For each qualifying line item and each unit index `0 … qty-1`:

- **Send now:** issue via ledger, email recipient, append to `_mp_cp_generated_gift_cards` (masked code, `code_last4`, delivery status — no `plain_code`).
- **Send on date:** append to `_mp_cp_pending_gift_card_deliveries` with recipient, message, `scheduled_for`, `delivery_status` = `pending_scheduled`.
- Mark `_mp_cp_gift_cards_generated` = `yes` when all slots are handled (immediate and/or pending).

**Idempotency:** Slots keyed by `order_item_id` + `unit_index`.

## Scheduled delivery runner

`GiftCardScheduledDeliveryService`:

- WP-Cron hook `mp_cp_gift_card_scheduled_delivery` (hourly when not already scheduled).
- **Diagnostics → Scheduled gift card delivery** — preview/run repair (due deliveries + cancel unpaid pending).
- **Order admin** — pending section, **Send due deliveries now** for that order.

## Delivery email

Plain `wp_mail()` when **Settings → Gift cards → Send gift card codes by email** is enabled. Includes amount, code, expiry, optional recipient/purchaser names and personal message. Never logged in full.

## Cancellation / refund

On `cancelled`, `refunded`, or `failed`:

- Unused generated cards: **void**.
- Pending scheduled rows: marked `cancelled` (not fulfilled).
- Partially used cards: order note for manual review.

## Admin & reporting

- **Gift Cards** tab — origin filters.
- **Reports** — `scheduled_pending`, `scheduled_sent`, `scheduled_failed`, `scheduled_cancelled`, plus delivery counters.
- **Diagnostics** — product generation integrity, delivery security, scheduled delivery checks.

## Not included

- Custom WooCommerce product type
- Promotion engine coupling
- Branded HTML email templates or calendar UI
- Storing full codes for deferred send

## Verification

```bash
composer run lint:php
composer run test
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-scheduled-delivery-smoke.php
```

See also [GIFT_CARDS_STORE_CREDIT.md](GIFT_CARDS_STORE_CREDIT.md), [MERCHANT_WORKFLOWS.md](MERCHANT_WORKFLOWS.md).
