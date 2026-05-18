# Customer store credit (wallet)

**Schema:** `1.19.0` extends `mp_cp_gift_cards` with `source_type`, `owner_customer_id`, and `label`.

Store credit is **customer-account balance** on the same ledger as gift cards. It is not a promotion rule and does not use `PromotionEvaluator`.

## Gift card vs store credit

| | Gift card | Store credit |
|---|-----------|--------------|
| Identity | Plain code (hashed at rest; product sales email code once, not stored in order meta) | Customer ID + currency wallet |
| Checkout (logged in) | Enter code | Apply account balance (no code) |
| Checkout (guest) | Code only | Not available |
| Admin | Issue / adjust / void | Grant / deduct / refund-to-credit |
| `source_type` | `gift_card` | `store_credit` |

## Wallet model

- One wallet per **customer + currency** (`code_hash` = deterministic SHA-256 of `mp_cp_store_credit_wallet:{customer_id}:{currency}`).
- `code_last4` is `WALL` (not a redeemable code).
- Transactions use the shared `mp_cp_gift_card_transactions` table (`issued`, `adjusted`, `redeemed`, `refunded`, `refund_to_credit`, …).

## Admin

**Commerce Growth → Gift Cards & Store Credit → Store Credit**

- Look up customer by ID, email, or login; choose **currency** from the WooCommerce currency list (store default).
- Grant or deduct with **required note**.
- View ledger.
- **Refund order to store credit (MVP)** — order ID, amount, note; requires `customer_id > 0` on the order. Not integrated into Woo’s refund UI yet.

## My Account

Logged-in customers see **Store credit wallet** on **My Account → Gift cards** (balance card + recent transactions). Empty state explains refunds and admin grants.

## Checkout MVP

- **Logged-in customers only** — session key `mp_cp_applied_store_credit`.
- Negative fee label: **Store credit balance**.
- Order meta: `_mp_cp_store_credit_redemptions` (+ recorded/reversed guards).
- Ledger debit on order processed; reversal on cancelled / failed / refunded.
- Guests may still use **gift card codes** from phase 1.

## Reports & diagnostics

Reports split gift card vs store credit liability and include refund-to-credit and manual adjustment totals.

Diagnostics add store-credit-specific checks (missing owner, unexpected `code_hash`, negative balance, ledger mismatch).

## Not in this milestone

- Full payment gateway / native Woo refund UI integration
- Purchasable gift card products — **done**; see [GIFT_CARD_PRODUCTS.md](GIFT_CARD_PRODUCTS.md)
- Blocks-specific store credit UI (fee path should not fatal)

## Verification

```bash
composer run lint:php
composer run test
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/store-credit-wallet-smoke.php
```
