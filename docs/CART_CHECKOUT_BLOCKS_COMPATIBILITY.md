# Cart / Checkout Blocks compatibility investigation

**Plugin:** MP Commerce Promotions `0.2.0-beta.1`  
**Schema:** `1.15.0`  
**Investigation milestone:** 2026-05-17 (setup) · **Manual QA:** 2026-05-18 · **Rendering fix:** 2026-05-16  
**Declaration:** `cart_checkout_blocks` remains **not declared**.  
**Status:** `mp_cp_block_compatibility_status` = **`partial`** — [BLOCKS_QA_EVIDENCE_2026-05-18.md](BLOCKS_QA_EVIDENCE_2026-05-18.md)

### Root cause (block UI empty on QA pages)

QA pages used **self-closing** block comments only (`<!-- wp:woocommerce/cart /-->`). WooCommerce Cart/Checkout block PHP `render()` returns the saved inner `$content`; with no inner block markup, `do_blocks()` and the front end output **no** `wp-block-woocommerce-cart` / `wc-block-cart` wrapper. Blocksy was not suppressing `the_content()` — the rendered content was empty.

**Fix:** `BlockTestPages::repair_page_block_markup()` sets `post_content` from `WC_Install::get_cart_block_content()` / `get_checkout_block_content()` (full inner block template). Run:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-rendering-diagnostic.php
```

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Block QA pages | **Pass** | **4333**, **4334** — full block markup after repair (live cart/checkout unchanged) |
| Block SSR / hydration | **Pass** (post-fix) | `do_blocks()` includes cart/checkout wrapper when inner structure present |
| Hook audit | **Partial** | Cart fees + checkout hooks verified; logger empty (WP_DEBUG off) |
| Block cart fees (fee-based) | **Partial** | CLI Pass; browser re-test after markup repair |
| Block stacked fees | **Blocked** | QA promos `exclusive` |
| Promotion code in block coupon UI | **Partial** | Re-test on 4334 after markup repair |
| Free shipping fee offset | **Partial** | CLI: no offset fee observed |
| Free gift | **Partial** | CLI Pass |
| Line item mode | **Partial** | Not verified in block UI |
| Checkout / reversal | **Partial** | CLI Pass (order 4342) |
| **Declare `cart_checkout_blocks`** | **No** | Block UI + codes/stacking incomplete |

Update `mp_cp_block_compatibility_status` (`not_tested` | `partial` | `passed` | `failed`) and notes after manual QA:

```bash
./wp option update mp_cp_block_compatibility_status partial
./wp option update mp_cp_block_compatibility_notes "Cart fees visible; codes not tested."
```

---

## Block QA pages (do not replace live storefront)

| Page | ID | Slug | Block markup | WC cart/checkout option |
|------|-----|------|--------------|-------------------------|
| Promotion Block Cart Test | **4333** | `mp-cp-block-cart-qa` | Full `woocommerce/cart` inner blocks (from `WC_Install`) | **Not set** |
| Promotion Block Checkout Test | **4334** | `mp-cp-block-checkout-qa` | Full `woocommerce/checkout` inner blocks | **Not set** |

**Live storefront (unchanged):** cart page **82** (`[woocommerce_cart]`), checkout **83** (`[woocommerce_checkout]`).

### Preview URLs

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-rendering-diagnostic.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-compatibility-smoke.php
```

Guest URLs (published):

- Cart: `https://www.biopentra.eu/mp-cp-block-cart-qa/`
- Checkout: `https://www.biopentra.eu/mp-cp-block-checkout-qa/`

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
| 1 | Fee-based **percentage** discount | Activate `MP CP Blocks QA — Fee 10%` (168) | Negative fee in totals | **Partial** (CLI Pass; browser Blocked) |
| 2 | Fee-based **fixed** discount | `MP CP Blocks QA — Fixed 5` (169) | Fixed fee visible | **Partial** (CLI Pass; browser Blocked) |
| 3 | **Stacked** fees (2+ stackable promos) | Set `application_mode` stackable on two QA promos | Both fees; subtotal correct | **Blocked** (QA promos exclusive) |
| 4 | **Promotion code** via block coupon field | Code linked to paused promo → activate | Virtual coupon valid; fee applies | **Blocked** |
| 5 | **Free shipping** fee offset | `MP CP Blocks QA — Free shipping` (170) | Shipping reduced / fee line | **Partial** (CLI: no offset seen) |
| 6 | **Free gift** add/remove | `MP CP Blocks QA — Free gift` (171) | Gift line $0; qty sync | **Partial** (CLI Pass) |
| 7 | **Line item** mode (experimental) | `MP CP Blocks QA — Line 10%` (172) | Line prices reduced in cart | **Partial** (not verified) |
| 8 | **Hybrid** fallback | Promo with line + gift/shipping | Fee fallback for gift/shipping | **Not run** |
| 9 | **Checkout** order recording | COD on block checkout | Order meta + redemption | **Partial** (CLI Pass order 4342) |
| 10 | **Redemptions** count | After order | `usage_count` incremented | **Partial** (CLI Pass) |
| 11 | **Reversal** | Cancel/refund order | Redemption reversed | **Partial** (CLI Pass) |
| 12 | **Native coupon coexistence** | WC coupon + MP promotion | Per `coupon_behavior` | **Not run** |
| 13 | **Guest checkout** | Incognito block checkout | Recording path | **Blocked** |
| 14 | **Logged-in checkout** | Customer account | Per-customer limits | **Not run** |

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
