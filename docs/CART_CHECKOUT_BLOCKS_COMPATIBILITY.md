# Cart / Checkout Blocks compatibility investigation

**Plugin:** MP Commerce Promotions `0.2.0-beta.1`  
**Schema:** `1.15.0`  
**Investigation milestone:** 2026-05-17 (post line-discount stabilization)  
**Declaration:** `cart_checkout_blocks` remains **not declared** in `WooCompatibility::declare_feature_compatibility()`.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Draft block QA pages | **Pass** | Cart **4333**, checkout **4334** (draft, not live cart/checkout) |
| Hook audit (documented) | **Pass** | See [Technical hook audit](#technical-hook-audit) |
| Optional hook debug log | **Available** | `WP_DEBUG` + `mp_cp_blocks_hook_debug=yes` |
| Block cart fees (fee-based) | **Not run** | Manual browser |
| Block stacked fees | **Not run** | Manual browser |
| Promotion code in block coupon UI | **Not run** | Virtual coupon filters untested on blocks |
| Free shipping fee offset | **Not run** | Manual browser |
| Free gift add/remove | **Not run** | `woocommerce_before_calculate_totals` path |
| Line item mode prices | **Not run** | Experimental; block cart line display unverified |
| Hybrid fallback | **Not run** | Fee path + line path |
| Checkout order recording | **Not run** | `woocommerce_checkout_create_order` likely; not browser-verified |
| Redemptions / reversal | **Not run** | Same hooks as classic; not block-verified |
| Native coupon coexistence | **Not run** | |
| Guest / logged-in checkout | **Not run** | |
| **Declare `cart_checkout_blocks`** | **No** | Until matrix below is Pass |

Update `mp_cp_block_compatibility_status` (`not_tested` | `partial` | `passed` | `failed`) and notes after manual QA:

```bash
./wp option update mp_cp_block_compatibility_status partial
./wp option update mp_cp_block_compatibility_notes "Cart fees visible; codes not tested."
```

---

## Block QA pages (do not replace live storefront)

| Page | ID | Slug | Block markup | WC cart/checkout option |
|------|-----|------|--------------|-------------------------|
| Promotion Block Cart Test | **4333** | `mp-cp-block-cart-qa` | `<!-- wp:woocommerce/cart /-->` | **Not set** |
| Promotion Block Checkout Test | **4334** | `mp-cp-block-checkout-qa` | `<!-- wp:woocommerce/checkout /-->` | **Not set** |

**Live storefront (unchanged):** cart page **82** (`[woocommerce_cart]`), checkout **83** (`[woocommerce_checkout]`).

### Preview URLs

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-compatibility-smoke.php
```

Or (replace host):

- Cart: `https://<site>/?page_id=4333` (admin may preview draft)
- Checkout: `https://<site>/?page_id=4334`

### Setup commands

```bash
# Ensure pages + paused QA promotions
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-compatibility-smoke.php

# Optional: log when audited hooks fire (requires WP_DEBUG true in wp-config)
./wp option update mp_cp_blocks_hook_debug yes
# Revert:
./wp option update mp_cp_blocks_hook_debug no
```

**Never** on production:

```bash
./wp option update woocommerce_cart_page_id 4333   # do not
./wp option update woocommerce_checkout_page_id 4334
```

---

## Manual test matrix

Record **Pass** | **Fail** | **Partial** | **Not run** in [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md).

| # | Scenario | Mode / notes | Expected | Status |
|---|----------|--------------|----------|--------|
| 1 | Fee-based **percentage** discount | Activate `MP CP Blocks QA — Fee 10%` | Negative fee or discount line in block cart totals | Not run |
| 2 | Fee-based **fixed** discount | `MP CP Blocks QA — Fixed 5` | Fixed fee discount visible | Not run |
| 3 | **Stacked** fees (2+ stackable promos) | Custom or duplicate QA promos | Both fees; subtotal correct | Not run |
| 4 | **Promotion code** via block coupon field | Code linked to paused promo → activate | Virtual coupon valid; native WC discount 0; fee applies | Not run |
| 5 | **Free shipping** fee offset | `MP CP Blocks QA — Free shipping` | Shipping reduced / free shipping fee line | Not run |
| 6 | **Free gift** add/remove | `MP CP Blocks QA — Free gift` | Gift line $0; qty sync on recalc; no orphan lines | Not run |
| 7 | **Line item** mode (experimental) | `MP CP Blocks QA — Line 10%` | Line unit/subtotal reduced in block cart if supported | Not run |
| 8 | **Hybrid** fallback | Promo with line + gift/shipping | Line actions apply; gift/shipping stay fee-based | Not run |
| 9 | **Checkout** order recording | COD or test gateway on block checkout | Order meta + `mp_cp_redemptions` row | Not run |
| 10 | **Redemptions** count | After order | `usage_count` incremented | Not run |
| 11 | **Reversal** | Cancel/refund order | Redemption reversed; usage decremented | Not run |
| 12 | **Native coupon coexistence** | WC coupon + MP promotion | Per `coupon_behavior` on promotion | Not run |
| 13 | **Guest checkout** | Incognito block checkout | Same as classic recording path | Not run |
| 14 | **Logged-in checkout** | Customer account | Per-customer limits respected | Not run |

---

## Technical hook audit

WooCommerce **Cart/Checkout Blocks** use the **Store API** (`/wc/store/v1/cart`, checkout endpoints). Server-side, WooCommerce still builds `WC()->cart` and runs total calculation for many flows. MP Commerce Promotions integrates via **classic cart/checkout hooks** (no Store API extension in this milestone).

### Plugin hooks (registered in `WooCommerceBridge`)

| Hook | MP CP handler | Expected in block flow | Confidence |
|------|---------------|------------------------|------------|
| `woocommerce_before_calculate_totals` (15) | `CartPromotionApplier::prepare_line_discount_cycle` | **Likely** on cart recalc (Store API cart updates) | Medium |
| `woocommerce_before_calculate_totals` (20) | `CartPromotionApplier::zero_free_gift_line_prices` | **Likely** same cycle | Medium |
| `woocommerce_cart_calculate_fees` (20) | `CartPromotionApplier::apply` | **Likely** — fees are the primary discount surface | Medium–High |
| `woocommerce_get_shop_coupon_data` | `PromotionCodeCouponBridge::filter_shop_coupon_data` | **Unknown** — block coupon UI may use Store API | Low |
| `woocommerce_coupon_is_valid` | `PromotionCodeCouponBridge::filter_coupon_is_valid` | **Unknown** | Low |
| `woocommerce_checkout_create_order` | `OrderPromotionRecorder::record_on_order_create` | **Likely** — WC block checkout creates orders via core checkout | Medium–High |
| `woocommerce_checkout_order_processed` | `OrderPromotionRecorder::record_on_checkout_processed` | **Likely** (fallback path) | Medium–High |
| Order status / trash hooks | Reversal / restore | **Likely** (order object / HPOS) | High |

### Related WooCommerce / Blocks surfaces (not hooked by MP CP)

| Surface | Relevance |
|---------|-----------|
| `WC()->cart->add_fee()` display | Block cart totals should reflect fees returned in Store API cart response |
| Store API `ExtendSchema` / cart extensions | **Not used** by MP CP in this milestone |
| `woocommerce_blocks_loaded` | Blocks package present; no MP CP listener |
| `woocommerce_store_api_*` | Cart/checkout REST; may not fire all classic hooks — **verify with hook debug** |
| `woocommerce_before_cart` | Degraded storefront notice — **may not run** on block-only cart routes |

### Debug hook logging

When **`WP_DEBUG`** is true and option **`mp_cp_blocks_hook_debug`** is `yes`, `BlocksHookAudit` logs (WooCommerce logger, source `mp-commerce-promotions-blocks`) when audited hooks fire, including whether the request is Store API (`/wc/store/`).

---

## QA promotions (paused)

Smoke script archives prior rows matching **`MP CP Blocks QA`** and creates paused drafts:

- Fee 10% (fee-based)
- Fixed 5 (fee-based)
- Free shipping
- Free gift (when a published product exists)
- Line 10% (line_item, experimental)

Activate only the promotion under test.

---

## Decision: declare `cart_checkout_blocks`?

Declare in `WooCompatibility::declare_feature_compatibility()` **only if all** manual matrix rows **1–6, 9–11** are **Pass** on draft block pages (or staging with block pages as live cart, then reverted):

1. Automatic promotion fees visible in block cart/checkout totals  
2. Promotion codes work (fee applies; native discount 0)  
3. Checkout records redemptions / order meta  
4. Reversal on cancel/refund  
5. Free gift sync acceptable  
6. No fatals with 2+ active promotions  

Until then: `CompatibilityStatus::cart_checkout_blocks_declared` stays **false**.

---

## Related docs

- [BLOCK_CHECKOUT_INVESTIGATION.md](BLOCK_CHECKOUT_INVESTIGATION.md)
- [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md)
- [manual-line-discount-engine-test.md](manual-line-discount-engine-test.md)
- [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md)
