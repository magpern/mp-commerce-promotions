# Manual test: line discount engine (schema 1.15.0)

## Scope

Storefront **line_item** and **hybrid** discount application modes. **Fee-based remains the default** for all promotions unless you change **Discount application** on the edit screen.

**Not in scope:** Cart/Checkout Blocks certification, subscription products, REST/AJAX admin.

## Prerequisites

- Plugin active; schema `1.15.0` (`wp option get mp_cp_schema_version`).
- Classic shortcode **cart** and **checkout** pages (not block checkout).
- Staging clone recommended; enable COD or BACS for checkout scenarios.
- Optional: `scripts/classic-browser-qa-setup.php` for seeded promos (adapt IDs for your catalog).

## Before pilot use

1. Run `scripts/line-discount-engine-smoke.php` (must pass).
2. Complete scenarios below on staging.
3. Review Reports → **Line discount mode** and Diagnostics recovery docs.
4. Confirm admin warnings on promotions using Line or Hybrid mode.

---

## 1. Simple product — percentage (line_item)

1. Create promotion: **Discount application** = **Line item (beta)**.
2. Action: `percentage_discount` 10%, no product scope (whole cart eligible lines).
3. Add one simple product to cart; open cart.
4. **Expect:** line unit price reduced (~10% off line subtotal); **no** duplicate negative fee for that promotion (unless hybrid fallback fired).
5. Cart preview (admin): allocation table + compatibility warnings if tax-inclusive.

## 2. Simple product — fixed (line_item)

1. Line item mode; action `fixed_amount_discount` €5 (or store currency).
2. Single line subtotal ≥ €5.
3. **Expect:** line total reduced by €5 (capped at line subtotal); not below zero.

## 3. Scoped category (line_item)

1. Action: percentage on `category_ids` matching cart line category.
2. Add qualifying + non-qualifying products.
3. **Expect:** only qualifying lines mutated; allocation metadata lists per `item_key`.

## 4. Scoped product (line_item)

1. Action: fixed or % with `product_ids` / `variation_ids`.
2. **Expect:** only matching SKU lines discounted.

## 5. Hybrid fallback

1. **Discount application** = **Hybrid**.
2. Use line-capable % action on a **supported** simple product → line mutation.
3. Add a **bundle** or **subscription** line (if present) with same promotion rules, or force failure (coupon block).
4. **Expect:** supported lines use line prices; failures recorded in fallback telemetry; **fee** may appear for hybrid when line path returns 0 for that promotion.
5. Reports: fallback count increments after cart calc (see **Line discount mode**).

## 6. Native coupon coexistence

| `coupon_behavior` | Test |
|-------------------|------|
| `coexist` | Native coupon + line promotion; verify totals and warnings |
| `block_native` | Native coupon applied → line promotion skipped |
| `require_no_coupon` | No native coupon → line promotion may apply |

## 7. Sale product behavior

1. Add **on-sale** simple product; line % promotion.
2. **Expect:** mutation uses current cart unit price (sale price if Woo uses it on cart line); verify displayed savings match expectations.
3. Compatibility analyzer may warn (sale price mode).

## 8. Tax-inclusive catalog

If `wc_prices_include_tax()` is true:

- Line mutation adjusts **unit price** only; **tax tables are not edited**.
- Compare cart subtotal, tax lines, and order totals on staging.
- Document any mismatch for merchant pilot notes.

## 9. Free gift + line mode

1. Promotion with **free_gift_product** + **percentage_discount**, line or hybrid mode.
2. **Expect:** gift line at zero via gift handler; %/fixed on **paid** lines via line mutation; free shipping / cheapest item still **fee-based**.

## 10. Checkout order line totals

1. Complete checkout (COD/BACS on staging).
2. **Expect:** order line items reflect discounted prices when line mode applied.
3. Order meta `_mp_cp_line_allocations` JSON present when session had allocations.
4. `_mp_cp_applied_promotions` includes `application_strategy` / `line_discount_applied` when applicable.

## 11. Refund / reversal expectations

1. Cancel or fail order after redemption recorded.
2. **Expect:** redemption reversed per existing plugin rules; **line meta is historical** (prices on order lines are not rewritten on cancel).
3. Partial refunds: **not** supported for automatic promotion reversal (unchanged).

---

## Diagnostics recovery

WooCommerce → Promotions → Diagnostics → **Pricing engine recovery**:

- **Repair stuck line discount sessions** — dry-run first.
- Clears: `mp_cp_line_allocations` session, `mp_cp_original_line_unit_price` on cart lines, in-request plan cache, restores line unit prices.

## Known limitations

- **Experimental** — do not enable line mode site-wide without staging sign-off.
- **Fee-based default** — existing promotions unchanged until edited.
- **Unsupported on line path:** free shipping, cheapest item, free gift (fee/gift mechanics).
- **Product types skipped:** subscriptions, bundles, composites.
- **Cart/Checkout Blocks** — not declared; use classic cart/checkout.
- **PHPCS / CI** — line engine not gated on PHPCS clean baseline.

## Smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/line-discount-engine-smoke.php
```
