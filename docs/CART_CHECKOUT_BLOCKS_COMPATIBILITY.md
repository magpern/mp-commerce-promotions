# Cart / Checkout Blocks compatibility investigation

**Date:** 2026-05-17  
**Plugin:** MP Commerce Promotions 0.1.0  
**Schema:** 1.14.0  
**Declaration status:** `cart_checkout_blocks` is **not** declared in `WooCompatibility::declare_feature_compatibility()`.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Cart fees in block cart | **Not verified** | Local Docker cart uses `[woocommerce_cart]` shortcode, not `woocommerce/cart` block |
| Virtual promotion codes in block checkout | **Not verified** | Block checkout coupon UI differs from classic coupon form |
| Free gift line sync | **Not verified** | Depends on `woocommerce_before_calculate_totals` during block cart recalc |
| `woocommerce_checkout_create_order` | **Likely** (classic proven) | WooCommerce fires this for block checkout orders; not re-tested on block pages here |
| Order meta / redemptions | **Likely** (HPOS + classic proven) | Same hooks path when checkout completes |
| Reversal on cancel/refund | **Likely** (classic proven) | Order status hooks unchanged |
| Storefront notices (degraded mode) | **Partial** | `woocommerce_before_cart` may not run on block-only cart routes |
| **Overall declaration** | **Do not declare** | Insufficient block-page evidence |

## Integration points (technical)

The plugin applies promotions through:

| Mechanism | Hook / API | Block relevance |
|-----------|------------|-----------------|
| Cart fees | `woocommerce_cart_calculate_fees` (priority 20) | Server-side cart totals; block cart uses Store API but typically still builds `WC()->cart` for checkout |
| Gift zero pricing | `woocommerce_before_calculate_totals` (priority 20) | Must run during cart total calculation |
| Promotion codes | `woocommerce_get_shop_coupon_data`, `woocommerce_coupon_is_valid` | Block checkout may apply coupons via Store API extensions; virtual coupon path untested |
| Checkout record | `woocommerce_checkout_create_order` | Standard for WC block checkout order creation |
| Reversal | `woocommerce_order_status_*`, trash/delete hooks | Order-object based; HPOS compatible |

WooCommerce Blocks package is **present** on the local Docker site (`Automattic\WooCommerce\Blocks\Package`), but cart/checkout **pages use shortcodes**, not block templates.

## Test environments

### Local Docker (`/home/magpern/woocommerce`)

| Check | Result |
|-------|--------|
| Cart page (ID 82) | `[woocommerce_cart]` shortcode — **not** `woocommerce/cart` block |
| Checkout page (ID 83) | `[woocommerce_checkout]` shortcode |
| Block cart smoke | **Blocked** — no block cart page configured |

### Production reference (`biopentra.eu`, prior evidence)

| Check | Result |
|-------|--------|
| Cart URL | `/cart-2/` (theme route; block vs classic not confirmed in this milestone) |
| Full checkout | **Blocked** — BTCPay-only; prior runs did not complete payment |
| Block checkout | **Not tested** |

## Manual test plan (when block pages exist)

1. Replace cart page content with `<!-- wp:woocommerce/cart /-->` and checkout with `<!-- wp:woocommerce/checkout /-->` (or use block templates).
2. Create active automatic promotion (e.g. 10% subtotal) and add product to cart.
3. **Fees:** Confirm negative fee line or equivalent discount in block cart totals.
4. **Code:** Apply promotion code in block coupon field; confirm fee applies and native WC discount stays 0.
5. **Free gift:** Confirm gift line at $0 and quantity sync on recalc.
6. **Checkout:** Place test order (cash on delivery or test gateway); verify `mp_cp_redemptions` row and order meta.
7. **Reversal:** Cancel order; verify redemption reversed and usage decremented.
8. **Notices:** Trigger degraded mode; confirm whether notice appears on block cart.

Record pass/fail in [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md).

## Remaining blockers before declaration

1. No block cart/checkout pages on the certified test environment (local Docker).
2. Block coupon field integration with virtual `shop_coupon_data` filter not exercised.
3. Unclear whether `woocommerce_before_cart` runs for block-only storefront flows (degraded notice).
4. Production merchant site not available for controlled block checkout in this run.
5. No automated Playwright/block E2E suite in CI.

## Recommendation

- Keep **`cart_checkout_blocks_declared` = false** in `CompatibilityStatus`.
- Merchants on **Cart/Checkout Blocks** should use **classic shortcode pages** or accept **unverified** behavior until this checklist passes.
- Revisit declaration only after a documented **pass** row for fees + codes + checkout recording on real block pages.

## Related docs

- [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md)
- [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md)
- [BETA_READINESS.md](BETA_READINESS.md)
