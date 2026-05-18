# Cart/Checkout Blocks QA evidence (2026-05-18)

**Site:** https://www.biopentra.eu  
**Plugin:** MP Commerce Promotions 0.2.0-beta.1 (schema 1.15.0)  
**QA pages:** 4333 (cart), 4334 (checkout) — published; live cart/checkout **82** / **83** unchanged  
**Latest QA promos (paused after run):** 193–197 (`MP CP Blocks QA — *`)  
**Harness:** `scripts/blocks-browser-cert.php`, `scripts/blocks-qa-runner.php`  
**Declaration:** `cart_checkout_blocks` **not declared** (`block_compatibility_status` = **partial**)

## Rendering (fixed in 2ce1c95)

Self-closing `<!-- wp:woocommerce/cart /-->` produced empty SSR. Repair uses full `WC_Install` inner block markup on 4333/4334. `do_blocks_has_wrapper` = yes; Blocksy default template OK.

## Browser certification matrix (2026-05-18)

Legend: **Pass** | **Fail** | **Partial** | **Not run**

| # | Scenario | Browser (4333 / 4334) | Server / Store API | Status |
|---|----------|----------------------|-------------------|--------|
| 1 | Fee % / fixed | **Pass** — promo **193** active; block cart shows **Commerce promotion … Fee 10% −4,60 €** on €46 MOTS-C subtotal | **Pass** — fee −€0.10 on €1 SKU 4338; Store API fees; order **4354** meta + usage + reversal | **Pass** |
| 2 | Stacked fees | Not run | **Partial** — two stackable QA promos → **1** negative fee on €1 SKU (planner/orchestration) | **Partial** |
| 3 | Promotion code | Not run (coupon UI present) | **Pass** (earlier cert run) / **Fail** when fee promo still active — use `BLOCKQA5` on promo **194** only | **Partial** |
| 4 | Free shipping | Not run | **Partial** — shipping_total=0 in CLI cart (no offset line) | **Partial** |
| 5 | Free gift | Not run | **Fail** — gift action targets SKU **4338** (same as paid test SKU); no second line | **Partial** |
| 6 | Line item mode | Not run | **Partial** — promo **197** active; no line allocations on €1 SKU | **Partial** |
| 7 | Hybrid fallback | Not run | Not run | **Not run** |
| 8 | Checkout recording | Checkout block UI **Pass** (Place Order, COD, order summary); full guest COD order not completed in browser | **Pass** — order **4354**: `_mp_cp_applied_promotions`, usage_count +1 | **Partial** |
| 9 | Reversal | Not run | **Pass** — cancel **4354**: usage 0→1→0 | **Pass** |
| 10 | Safe mode | Not run | **Pass** — no auto fees when `mp_cp_safe_mode` on | **Partial** |

### Browser notes

- Cart QA URL: https://www.biopentra.eu/mp-cp-block-cart-qa/
- Checkout QA URL: https://www.biopentra.eu/mp-cp-block-checkout-qa/
- Fee line visible in block cart totals when automatic promo **193** is **active** (screenshot: −4,60 € on €46 subtotal).
- Checkout order summary showed **55,99 €** (46 + 9,99 shipping) when fee promo was **not** active in session — re-test checkout totals with promo active before declaring compatibility.

## Orders / promotions referenced

| Artifact | ID |
|----------|-----|
| Block cart QA page | 4333 |
| Block checkout QA page | 4334 |
| Fee 10% QA promo | 193 |
| Fixed €5 QA promo | 194 |
| Free shipping QA promo | 195 |
| Free gift QA promo | 196 |
| Line 10% QA promo | 197 |
| Cert checkout order | 4354 |
| Promotion code (fixed) | `BLOCKQA5` → promo 194 |

## Declaration decision

**Do not declare** `cart_checkout_blocks`. Remaining gaps:

1. Stacked fees not proven (two fee lines) in block UI or CLI on current QA set.
2. Block coupon path not certified end-to-end in browser.
3. Free gift QA misconfigured (gift SKU = paid test SKU).
4. Line / hybrid block display not verified in browser.
5. Full block checkout COD order + fee persistence in checkout summary not completed in browser.

## Follow-up

- Point free-gift QA action at a SKU **different** from the paid cart line (e.g. gift 4338, paid MOTS-C 3702 in browser).
- Re-run checkout browser test with **193** active and confirm fee in checkout order summary / placed order meta.
- Optional: add second stackable fee QA pair with distinct orchestration groups.
