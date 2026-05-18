# Cart/Checkout Blocks QA evidence (2026-05-18)

**Site:** https://www.biopentra.eu  
**Plugin:** MP Commerce Promotions 0.2.0-beta.1 (schema 1.15.0)  
**QA pages:** 4333 (cart), 4334 (checkout) — **published** for guest access (still not assigned as live cart/checkout)  
**Declaration:** `cart_checkout_blocks` **not declared** (`block_compatibility_status` = **partial**)

## Environment prep

| Step | Result |
|------|--------|
| Draft pages 4333/4334 published for QA | Done (slug URLs work; `?page_id=` while draft returned 404 for guests) |
| Live `woocommerce_cart_page_id` / `checkout_page_id` | Unchanged (82 / 83) |
| `mp_cp_blocks_hook_debug` | Enabled during run; no `mp-commerce-promotions-blocks` log lines (WP_DEBUG likely off in production) |
| QA promotions paused between tests | Manual / script |

## Critical bug fixed during QA

| Issue | Fix |
|-------|-----|
| `LinePriceMutationGuard` referenced `Woo\AppliedLineDiscount` (missing class) → **fatal on every `woocommerce_before_calculate_totals`** | Added `use MP\CommercePromotions\Engine\AppliedLineDiscount` |

Without this fix, block and classic carts could white-screen when line-discount hooks ran.

## Manual matrix results

Legend: **Pass** | **Fail** | **Partial** | **Blocked**

| # | Scenario | Browser (block pages) | Server cart / hooks (WP-CLI) | Status |
|---|----------|----------------------|------------------------------|--------|
| 1 | Fee-based % / fixed | **Blocked** — cart block UI does not render (title only; scripts load) | **Pass** — promo 168: fee −0.10 on €1 product; promo 169: fee −1.00 | **Partial** |
| 2 | Stacked fees | **Blocked** | **Blocked** — QA promos are `exclusive` | **Blocked** |
| 3 | Promotion code (block coupon) | **Blocked** — no block checkout UI | Not run | **Blocked** |
| 4 | Free shipping offset | **Blocked** | **Partial** — promo 170 active; shipping €9.99; no fee line observed | **Partial** |
| 5 | Free gift | **Blocked** | **Pass** — promo 171: 2 lines, gift price 0 | **Partial** |
| 6 | Line item mode | **Blocked** | **Partial** — promo 172; no line allocation in CLI (investigate planner/applier separately) | **Partial** |
| 7 | Hybrid fallback | Not configured in QA set | Not run | **Not run** |
| 8 | Checkout recording | **Blocked** | **Pass** — order 4342: `_mp_cp_applied_promotions` set, `usage_count` +1 | **Partial** |
| 9 | Reversal | **Blocked** | **Pass** — cancel order 4342: redemption count 15→14, usage 0 | **Partial** |
| 10 | Notices / safe mode | Not run | Not run | **Not run** |

## Block UI finding (storefront)

- URL https://www.biopentra.eu/mp-cp-block-cart-qa/ loads WooCommerce Blocks **assets** (`cart-frontend.js`, Store API client).
- **No** `wp-block-woocommerce-cart` markup in HTML; main content is page title only (Blocksy layout).
- Mini-cart shows session items (e.g. 46 €, 2 lines) but **block cart/checkout components do not mount**.
- **Conclusion:** Block matrix cannot be completed in the browser on this theme/page setup until the theme renders `the_content()` blocks (separate from MP CP).

## Hook audit

| Hook | Expected | Evidence |
|------|----------|----------|
| `woocommerce_before_calculate_totals` | Yes | Required for gift + line cycle; fatal before fix confirmed hook runs |
| `woocommerce_cart_calculate_fees` | Yes | Fees observed on cart totals |
| `woocommerce_checkout_create_order` | Yes | Order meta + usage on test order 4342 |
| `woocommerce_checkout_order_processed` | Yes | Called in test |
| Coupon bridge | Unknown | Not exercised |
| Store API | Scripts load | No logged Store API hook audit lines |

## Declaration decision

**Do not declare** `cart_checkout_blocks`. Blockers:

1. Block cart/checkout UI not rendering on QA pages (theme/content).
2. Browser paths for fees, codes, gifts, and checkout untested.
3. Stacked fees and promotion codes not exercised.
4. Line/hybrid block display not verified.

## Follow-up

- Fix theme/page template so `<!-- wp:woocommerce/cart /-->` renders on 4333/4334.
- Re-run browser matrix; consider stackable QA promotions for stacked-fee test.
- Retest line mode on storefront (not only WP-CLI).
