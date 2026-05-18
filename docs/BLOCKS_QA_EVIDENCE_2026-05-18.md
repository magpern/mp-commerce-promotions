# Cart/Checkout Blocks QA evidence (2026-05-18)

**Site:** https://www.biopentra.eu  
**Plugin:** MP Commerce Promotions 0.2.0-beta.1 (schema 1.15.0)  
**QA pages:** 4333 (cart), 4334 (checkout) — published; live cart/checkout **82** / **83** unchanged  
**Latest QA promos (paused after run):** 238–244 (`MP CP Blocks QA — *`)  
**QA products:** paid **4356** (`mp-cp-block-qa-paid`, €5), gift **4338** (`mp-cp-block-qa-gift`, €1)  
**Promotion code (current batch):** `BLOCKQA239` (links fixed promo **239**; legacy `BLOCKQA218` points to archived promo **218**)  
**Harness:** `scripts/blocks-browser-cert.php`, `scripts/blocks-qa-runner.php`, `BlockQaPromotionSetup`  
**Declaration:** `cart_checkout_blocks` **declared** (`mp_cp_block_compatibility_status` = **passed**)

## Bug fixed (final browser pass)

Block checkout uses `woocommerce_store_api_checkout_order_processed`, not `woocommerce_checkout_create_order`. `WooCommerceBridge` now records order meta, redemptions, and usage on Store API checkout (orders **4362**, **4363** verified).

## Browser certification (final pass)

| Scenario | Result | Evidence |
|----------|--------|----------|
| Fee in block cart/checkout | **Pass** | Commerce promotion fee line; COD order **4362** (prior pass) |
| Block COD checkout + recording | **Pass** | Order **4363** — `_mp_cp_applied_promotions`, redemption, code usage |
| Block coupon UI | **Pass** | `BLOCKQA239` accepted; fee −€5; order **4363** total €9.99 |
| Free gift | **Pass** (server) | CLI cert; browser not re-run (no fatal on prior passes) |
| Line item mode (visual) | **Partial** | Block cart shows unit **€5.00**; server mutates line price (CLI Pass) |
| Free shipping offset | **Partial** | Not re-run; shipping €9.99 on QA checkout |
| Stacked fees | **Pass** (server) | CLI cert two negative fees |

**Browser orders:** fee COD **4362**; code coupon COD **4363** (cancel/reversal not required for code path this pass).

## CLI / Store API certification

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-browser-cert.php
```

**Summary:** 8 pass, 0 fail (promos 238–244; cert order **4364** fee reversal).

## Declaration decision

**Declare** `cart_checkout_blocks` via `FeaturesUtil::declare_compatibility`. Critical paths Pass in browser + server:

- Fee display in block cart/checkout  
- Block checkout order recording (Store API hook)  
- Block coupon UI with current batch code `BLOCKQA239`  
- Gift does not break cart (CLI)  
- No fatal/degraded behavior observed  

**Residual (non-blocking):** block line-item unit price display; free-shipping offset; use current-batch codes after `blocks-compatibility-smoke.php` refresh (archived promos invalidate legacy codes such as `BLOCKQA218`).
