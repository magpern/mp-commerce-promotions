# Cart/Checkout Blocks QA evidence (2026-05-18)

**Site:** https://www.biopentra.eu  
**Plugin:** MP Commerce Promotions 0.2.0-beta.1 (schema 1.15.0)  
**QA pages:** 4333 (cart), 4334 (checkout) — published; live cart/checkout **82** / **83** unchanged  
**Latest QA promos (paused after run):** 217–223 (`MP CP Blocks QA — *`)  
**QA products:** paid **4356** (`mp-cp-block-qa-paid`, €5), gift **4338** (`mp-cp-block-qa-gift`, €1)  
**Harness:** `scripts/blocks-browser-cert.php`, `scripts/blocks-qa-runner.php`, `BlockQaPromotionSetup`  
**Declaration:** `cart_checkout_blocks` **not declared** (`block_compatibility_status` = **partial**)

## Rendering (fixed in 2ce1c95)

Self-closing `<!-- wp:woocommerce/cart /-->` produced empty SSR. Repair uses full `WC_Install` inner block markup on 4333/4334. `do_blocks_has_wrapper` = yes; Blocksy default template OK.

## Focused certification rerun (2026-05-18, post–QA setup fix)

Command:

```bash
MP_CP_BLOCK_CERT_SCENARIOS=stacked,code,gift,line,fee \
  ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-browser-cert.php
```

| Scenario | Server / Store API | Status | Notes |
|----------|-------------------|--------|-------|
| Fee 10% cart + checkout record | **Pass** — negative fee; order **4360** `_mp_cp_applied_promotions`; usage 0→1→0 on cancel | **Pass** | Paid SKU 4356 |
| Stacked fees | **Pass** — `negative_fees=2`, `applied_promotions=2` (stack 3% + stack 2 fixed, `stop_processing=false`) | **Pass** | |
| Promotion code | **Pass** — all automatic QA promos paused; `BLOCKQA218` + fee −€5; single linked promo in session | **Pass** | Browser coupon UI not re-run this pass |
| Free gift | **Pass** — 2 lines; `mp_cp_free_gift=yes`, gift price 0; paid 4356 ≠ gift 4338 | **Pass** | Browser MOTS-C + gift still recommended for variable catalog |
| Line item mode | **Pass** — line price mutated (€5 → €4.50, 10%); session payload cleared after WC multi-pass totals | **Pass** | Block UI line display not re-run; server-side line mode proven |
| Full block COD checkout (browser) | Not completed | **Partial** | Checkout block UI renders on 4334; guest COD order with active fee not placed in browser this pass |

**Orders:** fee/cert **4360** (record + reversal). Prior cert order **4354** still valid for earlier fee/reversal evidence.

## QA setup fixes (this pass)

1. **Stacked** — dedicated stackable QA promos (`application_mode=stackable`, `stop_processing=false`).
2. **Code** — pause all planner-active promos (not only QA prefix); code `BLOCKQA{id}` when `BLOCKQA5` is stale.
3. **Gift** — `ensure_distinct_addable_product_pair()` provisions paid/gift simple SKUs; gift action uses addable gift ID only.
4. **Line** — cert primes `woocommerce_before_calculate_totals` before totals; detects mutated line price; `CartPromotionApplier` skips clearing session on WC early subtotal=0 pass.
5. **Scripts** — cert/runner no longer call `Plugin::init()` (avoids duplicate cart hooks).

## Browser certification matrix (cumulative)

| # | Scenario | Browser (4333 / 4334) | Server / Store API | Status |
|---|----------|----------------------|-------------------|--------|
| 1 | Fee % / fixed | **Pass** (prior) — fee line on block cart with promo active | **Pass** — cert order 4360 | **Pass** |
| 2 | Stacked fees | Not run | **Pass** — two negative fees | **Pass** (server) |
| 3 | Promotion code | Not run this pass | **Pass** — `BLOCKQA218` | **Partial** (browser UI) |
| 4 | Free shipping | Not run | Not re-run | **Partial** (prior) |
| 5 | Free gift | Not run | **Pass** — distinct SKUs | **Pass** (server) |
| 6 | Line item mode | Not run | **Pass** — mutated line price | **Partial** (block UI display) |
| 7 | Hybrid fallback | Not run | Not run | **Not run** |
| 8 | Checkout recording | Checkout block UI **Pass**; full guest COD order **not** placed | **Pass** — orders 4354, 4360 | **Partial** |
| 9 | Reversal | Not run | **Pass** | **Pass** |
| 10 | Safe mode | Not run | **Pass** (prior) | **Partial** |

## Declaration decision

**Do not declare** `cart_checkout_blocks`. Remaining gaps:

1. Block **coupon UI** not certified in browser this pass (server/code path Pass).
2. **Line / hybrid** block cart price display not verified in browser (server line mutation Pass).
3. **Full block COD checkout** with active fee not completed in browser (CLI checkout record/reversal Pass).
4. Free shipping offset not re-certified.

## Follow-up

- Browser: activate fee promo **217**, add MOTS-C (3702) on cart QA **4333**, complete COD on checkout QA **4334**, confirm fee in order summary and placed order meta.
- Browser: apply `BLOCKQA218` in block coupon field; confirm fee + single promo in order.
- Optional: re-run free shipping with paid shipping method selected.
