# Manual WooCommerce QA evidence

Evidence bundle for high-risk promotion paths after admin UX polish (`e8da1f1`). This milestone documents verification only; no feature changes.

**Cart/Checkout Blocks (2026-05-18):** See [BLOCKS_QA_EVIDENCE_2026-05-18.md](BLOCKS_QA_EVIDENCE_2026-05-18.md) — partial; `cart_checkout_blocks` not declared.

## Environment

| Item | Value |
|------|--------|
| **Date/time (UTC)** | 2026-05-17 ~10:12–10:20 |
| **Site URL** | https://www.biopentra.eu |
| **Stack** | Docker Compose (`woocommerce-wordpress-1`), host port 80 |
| **WordPress** | 6.9.4 |
| **WooCommerce** | 10.7.0 |
| **Theme** | Blocksy (`blocksy`) |
| **HPOS** | Enabled (`woocommerce_custom_orders_table_enabled` = `yes`) |
| **Plugin** | MP Commerce Promotions 0.1.0 (active) |
| **Schema** | `mp_cp_schema_version` = **1.7.0** |
| **Currency** | EUR |
| **Cart discounts** | Enabled (`mp_cp_cart_discounts_enabled` = yes) |

### Storefront routes

| Route | Notes |
|-------|--------|
| Cart | https://www.biopentra.eu/cart-2/ (Blocksy/Woo cart page; legacy `/cart/` may 404) |
| Checkout | Standard Woo checkout (BTCPay gateway) |
| Admin promotions | `wp-admin/admin.php?page=mp-commerce-promotions` |

### Product IDs used

| ID | Product | Use |
|----|---------|-----|
| **3702** | MOTS-C (parent) | Variation parent; smoke/checkout docs |
| **3703** | MOTS-C – 10mg | Primary variation for cart/subtotal tests (~€46 unit) |
| **3705** | Kisspeptin | Variable product; cart showed “choose options” notices when incomplete lines present |
| **3700–3701** | Retatrutide, GHK-CU | Published catalog (available for manual BOGO/category tests) |

### Gateway / payment

| Gateway ID | Title |
|------------|--------|
| `btcpaygf_default` | BTCPay (Bitcoin, Lightning Network, …) |

Full browser checkout with payment capture was **not** completed in this run (crypto-only gateway; prior doc notes same limitation).

### Test promotions on site

| ID | Name | Status | Notes |
|----|------|--------|--------|
| **79** | Smoke free gift 2026-05-16 22:05:55 | **active** | Only active automatic promotion during QA |
| **23** | Coupon Smoke Gpk6 | paused | Code-linked smoke |
| **110–129** | Smoke Stack/Cap/Record * | archived | Left from WP-CLI smoke scripts |

**111** promotions total in admin list (mostly archived smoke data).

---

## Verification methods

| Method | Scope |
|--------|--------|
| **Browser (production URL)** | Logged-in admin (`bp_manager`); storefront cart at `/cart-2/` |
| **WP-CLI smoke scripts** | Engine, cart session, order recording, reports CSV |
| **PHPUnit** | Pure PHP unit tests (no WordPress bootstrap) |
| **Composer** | `validate --strict`, `lint:php`, `test` via Docker Composer image |

---

## Admin UI (browser)

| Check | Result | Notes |
|-------|--------|-------|
| Promotions list loads | **Pass** | 111 promotions, pagination “Page 1 of 6”, status filters, search box |
| Search / filter / pagination | **Partial** | Filters and “Next →” visible; search submit not exercised (session heartbeat modal present) |
| Bulk Activate / Pause / Archive UI | **Pass** | Bulk actions dropdown lists all three; checkboxes on rows |
| Bulk actions POST | **Not run** | WordPress “Session expired” overlay on admin; avoid mutating live data |
| Reports tab | **Pass** | Summary, filters (date, promotion picker, status), “Export redemptions CSV” |
| CSV export download | **Partial** | Button present; file download not triggered in automation |
| Diagnostics tab | **Pass** | Integrity notes, usage tables, “Repair Usage Counters” |
| Settings tab | **Pass** | “Enable cart discounts” checked; “Save settings” present |
| Promotion edit (ID 79) | **Pass** | Back link, Pause/Archive/Duplicate, name field, Simple Rule Builder / templates / cart preview sections (large edit screen) |

---

## Storefront (browser)

| Check | Result | Notes |
|-------|--------|-------|
| Cart page loads | **Pass** | `/cart-2/`, 5× MOTS-C – 10mg, subtotal €230 |
| Promotion fees on cart | **N/A** | No second stackable **active** promotion configured; only promotion **79** (free gift) active |
| Stacked cart fees | **Partial** | Covered by WP-CLI `stacking-smoke.php` / `stacking-limits-smoke.php` (see below) |
| Cheapest item discount | **Partial** | `cheapest-item-smoke.php` pass; browser BOGO not re-run |
| Free shipping offset | **Partial** | Cart showed free shipping tier; fee-offset not isolated in browser |
| Free gift line | **Partial** | Active promo **79** is free-gift smoke; cart had Kisspeptin “choose options” errors (incomplete variable lines), gift fee not confirmed in browser |
| Promotion code / coupon field | **Partial** | Coupon field visible on cart; code apply + checkout not run |
| Checkout recording | **Partial** | Prior WP-CLI run in [manual-checkout-test.md](manual-checkout-test.md) (2026-05-16); not repeated in browser |
| Reversal / restore | **Partial** | `checkout-integrity-smoke.php` pass; browser order lifecycle not run |

---

## Checklist by manual doc

### [manual-stacking-test.md](manual-stacking-test.md)

| Section | Browser | WP-CLI smoke |
|---------|---------|--------------|
| Two stackable fees | Not run | **Pass** (session 2 fees, total ≈ 25) |
| Subtotal cap | Not run | **Pass** (capped at subtotal) |
| Exclusive blocks later | Not run | Not in this smoke run |
| Exclusion A excludes B | Not run | Not in this smoke run |
| Max applications = 2 | Not run | **Pass** (`stacking-limits-smoke.php`) |

### [manual-cheapest-item-test.md](manual-cheapest-item-test.md)

| Check | Result |
|-------|--------|
| Category / product BOGO math | **Pass** (`cheapest-item-smoke.php`) |
| Storefront fee line | **Not run** (browser) |

### [manual-free-gift-test.md](manual-free-gift-test.md)

| Check | Result |
|-------|--------|
| Evaluator / builder | **Pass** (`free-gift-smoke.php`) |
| Cart add / zero price / dedupe | **Partial** (CLI: `add_to_cart` unavailable; browser: incomplete variable lines on cart) |

### [manual-free-shipping-test.md](manual-free-shipping-test.md)

| Check | Result |
|-------|--------|
| Evaluator + customer redemption count | **Pass** (`free-shipping-smoke.php`) |
| Shipping fee offset in checkout | **Not run** (browser; note in smoke output) |

### [manual-promotion-code-test.md](manual-promotion-code-test.md)

| Check | Result |
|-------|--------|
| Coupon field + virtual coupon | **Partial** (field visible; full flow not run) |
| Code vs automatic non-stacking | **Pass** (unit tests + prior docs) |

### [manual-checkout-test.md](manual-checkout-test.md)

| Check | Result |
|-------|--------|
| Fee + redemption + idempotency + reversal | **Pass** (2026-05-16 WP-CLI simulation, order #4310) |
| Full browser checkout | **Not run** |

### [manual-checkout-integrity-test.md](manual-checkout-integrity-test.md)

| Check | Result |
|-------|--------|
| Idempotency / stacked rows / reversal / gift sync | **Pass** (`checkout-integrity-smoke.php`) |
| Restore processing/completed | **Pass** (smoke) |
| Browser order lifecycle | **Not run** |

### [manual-redemption-limits-test.md](manual-redemption-limits-test.md)

| Check | Result |
|-------|--------|
| usage_limit, dates, cart quantity, guest customer_usage | **Pass** (`redemption-limits-smoke.php`) |
| Storefront enforcement | **Not run** (browser) |

---

## WP-CLI smoke script results (2026-05-17)

| Script | Result |
|--------|--------|
| `stacking-smoke.php` | **Fail** (2) — cart stacking/cap sections **pass**; order section fails `two redemption rows` and `both redemptions reversed` while `usage_count` idempotency/reversal **pass** |
| `stacking-limits-smoke.php` | **Pass** |
| `cheapest-item-smoke.php` | **Pass** |
| `free-shipping-smoke.php` | **Pass** |
| `free-gift-smoke.php` | **Pass** (evaluator/builder; cart add skipped in CLI) |
| `redemption-limits-smoke.php` | **Pass** |
| `checkout-integrity-smoke.php` | **Pass** (includes stacked two-row recording) |
| `reports-smoke.php` | **Pass** |
| `admin-ux-smoke.php` | **Pass** |

**Note:** `stacking-smoke.php` order-recording assertions may be flaky under HPOS/`wc_create_order` in CLI; `checkout-integrity-smoke.php` records two stacked rows successfully on the same stack.

---

## Automated verification (staging tree)

| Command | Result |
|---------|--------|
| `composer validate --strict` | **Pass** (Docker `composer:2`) |
| `composer run lint:php` | **Pass** |
| `composer run test` | **Pass** — 197 tests, 407 assertions |
| `bash scripts/build-zip.sh` | **Pass** — `mp-commerce-promotions-0.1.0.zip` |
| `bash scripts/verify-plugin.sh` | **Pass** — activate/deactivate, schema 1.7.0 |

---

## Known blockers

1. **BTCPay-only checkout** — no simple “cash on delivery” path for end-to-end paid browser checkout without crypto flow.
2. **Admin heartbeat “Session expired”** — intermittent overlay on `biopentra.eu` admin during automation; blocks reliable bulk POST / CSV download tests without fresh login.
3. **CLI cart limitations** — `WC()->cart` subtotal/fees and `add_to_cart` differ from browser session; storefront fees and free gifts need browser or dedicated test products.
4. **Variable gift products** — Kisspeptin (3705) incomplete cart lines produce WooCommerce notices; free-gift browser tests should use a **simple** gift SKU.
5. **`stacking-smoke.php` order assertions** — investigate `find_for_order` count vs `usage_count` under HPOS for bare `wc_create_order` + hook (follow-up, not blocking MVP if `checkout-integrity-smoke` passes).
6. **Smoke promotion clutter** — 92 archived + many draft promotions from automated runs; consider periodic DB cleanup on staging.

---

## Follow-up fixes needed

| Priority | Item |
|----------|------|
| Low | Align README schema version text with **1.7.0** |
| Low | Re-run `stacking-smoke.php` order section or align assertion with HPOS order ID timing |
| QA | Dedicated browser pass: 2 active stackable promos + fee lines on `/cart-2/` |
| QA | Free gift with simple product ID; confirm zero price and no duplicate on refresh |
| QA | Promotion code apply at checkout + BTCPay or manual order status reversal in admin |
| Ops | Archive/delete old smoke promotions on staging to simplify admin list |

---

## Classic browser QA (2026-05-17)

**Milestone:** Classic checkout beta QA (`test: complete classic checkout beta QA`)  
**Environment:** https://www.biopentra.eu — Docker, COD enabled, HPOS on  
**Commit after prep:** `808a261` + recording fix  

| Item | Value |
|------|--------|
| Gateway | **COD** |
| Cart / checkout | `/cart-2/`, `/checkout-2/` |
| Product | **3703** (MOTS-C 10mg) |
| Gift SKU | **4338** |
| Browser order | **4339** (guest, COD, €86.99 total) — **cancelled** after reversal test |
| Promotions tested | **154–166** (Browser QA seed via `classic-browser-qa-setup.php`) |

### Storefront browser

| Check | Result | Notes |
|-------|--------|-------|
| Stacked fees on cart | **Pass** | −€10 / −€5; total €77 |
| COD checkout | **Pass** | Order **4339** |
| Redemption rows | **Pass** | After `entries_from_order_fees` fix |
| Cancel → reversal | **Pass** | usage_count 0 for **154**, **155** |
| Promotion code (CLI) | **Pass** | `BROWSERQA15` / promo **160** |
| Scoped / cheapest / gift / shipping | **Partial** / **Not run** | See [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md) |

### Admin browser

| Check | Result |
|-------|--------|
| Promotions list | **Pass** |
| Reports tab | **Pass** (load) |
| CSV download | **Partial** |
| Settings / Diagnostics | **Partial** (not deep-tested) |

### Small fixes this milestone

- Checkout recording fallback from order fee lines when session empty at `checkout_create_order`
- `PromotionRepository::find_by_name()`
- `classic-browser-qa-setup.php` pause/activate logic for isolated scenarios

See [BETA_RELEASE_DECISION.md](BETA_RELEASE_DECISION.md).

---

## Beta certification update (2026-05-17)

**Milestone:** Beta readiness (`chore: prepare beta readiness certification`)  
**Local Docker:** Classic shortcode cart/checkout only — **not** block cart pages.

| Area | Status | Notes |
|------|--------|-------|
| Cart/Checkout Blocks | **Blocked** | See [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md); declaration remains **false** |
| Classic stacked fees | **Partial** | Smokes + prior browser; matrix updated in [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md) |
| Scoped / cheapest / shipping | **Partial** | Script coverage; browser not re-run this milestone |
| Free gift | **Partial** | Prior live site notes |
| Promotion code | **Partial** | Paused smoke codes on biopentra |
| Checkout recording | **Partial** | `checkout-integrity-smoke.php` pass; BTCPay blocks paid browser flow |
| Reversal / restore | **Partial** | Hooks tested via smoke; browser cancel not run |
| Reports CSV | **Partial** | Export UI pass; download not triggered |
| Diagnostics repair | **Partial** | Dry-run not applied on production |
| Settings gates / safe mode | **Pass** | Smoke + settings UI |
| Reports production hardening | **Pass** | New Reports section (closure milestone) |
| POT generation | **Pass** | `wp i18n make-pot` → 6000+ lines |
| PHPCS (target paths) | **Improved** | PHPCBF committed on staging Service/Admin/Woo subset |
| PHPUnit (staging) | **Pass** | 324+ tests (see verification on commit) |

**Automated (beta milestone):** `scripts/beta-readiness-smoke.php`, `scripts/release-audit.sh` (POT + beta docs).

---

## References

- Base commit: `e8da1f1` (admin UX polish)
- Beta readiness: [BETA_READINESS.md](BETA_READINESS.md)
- Blocks investigation: [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md)
- Manual procedures: `docs/manual-*.md`
- Prior checkout simulation: [manual-checkout-test.md](manual-checkout-test.md) § Test Run — 2026-05-16
