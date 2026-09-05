# UMC bulk pricing — set_price() spike (A-1)

**Status:** Passed (code-path + base-currency dev smoke)  
**Date:** 2026-09-05

## Goal

Determine the canonical currency/amount for `WC_Product::set_price()` when Universal Multicurrency (UMC) is active in a non-base display currency, and confirm no compounding across consecutive `calculate_totals()` passes.

## Evidence

### 1. UMC cart conversion tests (`UMC\Tests\Integration\CartConversionTest`)

- Cart line totals are **unit-price authoritative** in the **active display currency**.
- `test_no_double_conversion_of_cart_line_price`: a €100 product at rate 11.5 yields **1150 SEK** total — never `1150 × 11.5`.
- `CartRecalculation` documents: cart stores product references; `calculate_totals()` recomputes from product getters — converted values are not reused as conversion input.

### 2. Gift card pattern in mp-commerce-promotions

`GiftCardCustomerAmountCart` stores the face value in **shop base currency** on the cart line and calls `set_price()` with that **base** amount. UMC `woocommerce_product_get_price` filters convert base → display at read time. Display formatting uses `GiftCardStorefrontAmounts::display_amount_from_base()`.

### 3. Dev WP-CLI smoke (`scripts/umc-bulk-pricing-spike.php`)

- **Base currency (EUR) path:** passes — two `calculate_totals()` passes produce identical subtotals; fresh `wc_get_product()` price unchanged after cart `set_price()`.
- **Non-base CLI limitation:** `CurrencyContext` is not convertible in WP-CLI (active currency remains EUR). Storefront non-base behaviour is covered by UMC integration tests above.

## Decision (binding)

| Concern | Rule |
|---------|------|
| **Snapshot (quote) currency** | Active **display/transaction** currency minor units from fresh `wc_get_product( $id )->get_price()` at capture time |
| **Quote arithmetic** | Integer minor units in display currency |
| **`set_price()` commit currency** | **Shop base currency** decimal string (same as gift cards), computed as `GiftCardStorefrontAmounts::convert_display_to_base( display_unit )` when display ≠ base; otherwise display amount |
| **Order persistence** | WooCommerce line subtotal/total in order currency; UMC `_umc_*` meta unchanged |
| **Compounding prevention** | Never read `cart_item['data']->get_price()` for base capture; only fresh catalog product |

## Rationale

Setting display-currency amounts via `set_price()` while UMC `get_price` filters remain active risks double conversion on subsequent reads. Committing **base-authored** unit prices matches the proven gift-card integration and lets UMC convert exactly once per totals pass.

## Regression

- `scripts/umc-bulk-pricing-spike.php` — runnable on dev (base currency).
- `tests/Unit/BulkPricingMoneyTest.php` — minor-unit arithmetic.
- `tests/Unit/CatalogBasePriceResolverTest.php` — pristine catalog capture.
- UMC `CartConversionTest` — display currency cart totals (upstream).
