# Classic checkout certification

**Environment:** Local Docker — https://www.biopentra.eu  
**Certification date:** 2026-05-17  
**Commit baseline:** `808a261` + classic QA milestone (checkout recording fix)  
**Cart page:** ID 82 (`/cart-2/`) — `[woocommerce_cart]`  
**Checkout page:** ID 83 (`/checkout-2/`) — `[woocommerce_checkout]`  
**Gateway:** COD (`woocommerce_cod_settings[enabled]=yes`)  
**HPOS:** Enabled  

**Legend:** Pass | Fail | Partial | Blocked | Not run

---

## Summary

| Area | Status |
|------|--------|
| **Engine / WP-CLI smokes** | **Pass** |
| **Browser E2E (classic + COD)** | **Pass** with caveats — stacked checkout, recording fix, reversal; several scenarios partial/not run |
| **Production browser** | **Blocked** — BTCPay-only |

---

## Test assets (2026-05-17)

| Asset | ID / value |
|-------|------------|
| Qualifying product | **3703** (MOTS-C 10mg, €46) |
| Gift product | **4338** (Browser QA Gift SKU) |
| Stacked promos | **154**, **155** |
| Scoped % | **156** |
| Cheapest item | **157** |
| Free shipping | **158** |
| Free gift | **159** |
| Code promo | **160** — code `BROWSERQA15` |
| Budget | **161** |
| Cooldown | **162** |
| Orchestration | **163**, **164** — group `browser-qa-lane` |
| Exclusion pair | **165**, **166** |
| Browser COD order | **4339** (cancelled after reversal test) |

---

## Certification matrix

| Scenario | WP-CLI / smoke | Browser (classic) | Notes |
|----------|----------------|-------------------|--------|
| **Stacked fees** | **Pass** | **Pass** | Cart €77 = €92 − €10 − €5; fees on order **4339** |
| **Scoped discounts** | **Pass** (unit) | **Partial** | CLI 0 fees with promo **156** + product **3703** |
| **Cheapest item** | **Pass** | **Not run** | Promo **157** seeded |
| **Free shipping** | **Pass** | **Partial** | CLI no shipping calc; promo **158** |
| **Free gift** | **Partial** | **Partial** | Promo **159**, gift **4338**; line not confirmed in browser |
| **Promotion code** | **Partial** | **Pass** (CLI) | Code `BROWSERQA15`, −€15 fee; browser coupon not isolated |
| **Checkout recording** | **Pass** | **Pass** | Order **4339** — initial miss fixed via fee fallback (see below) |
| **Usage counts** | **Pass** | **Pass** | **154**/**155** → 1 then 0 after cancel |
| **Code usage** | **Partial** | **Not run** | |
| **Reversal** | **Pass** | **Pass** | Order **4339** → `cancelled`; redemptions `reversed` |
| **Restore** | **Partial** | **Not run** | Smoke only |
| **Orchestration** | **Pass** (CLI) | **Partial** | One fee (Orch A −€4) when **163**+**164** active |
| **Exclusion** | **Partial** | **Partial** | Both **165** and **166** fees in CLI — investigate |
| **Budget / cooldown** | **Partial** (smoke) | **Not run** | **161**, **162** |
| **Reports / CSV** | **Partial** | **Partial** | Reports tab loads; export not downloaded |
| **Diagnostics / repair** | **Partial** | **Partial** | Tab reachable; repair/bundle not run |

---

## Browser sign-off (2026-05-17)

| Check | Tester | Date | Pass? |
|-------|--------|------|-------|
| Place COD order with stacked promotions | Agent (browser) | 2026-05-17 | **Yes** — order **4339** |
| Cart shows stacked fee lines | Agent | 2026-05-17 | **Yes** |
| Redemption rows after checkout | Agent + WP-CLI | 2026-05-17 | **Yes** (after recording fix) |
| Cancel order → usage reversed | Agent + WP-CLI | 2026-05-17 | **Yes** |
| CSV export downloaded | — | — | **No** |

**Approver:** ___________________ **Date:** ___________

---

## Checkout recording fix (QA bug)

Order **4339** placed successfully with promotion fees on the order, but session payload was empty at `woocommerce_checkout_create_order`, so redemptions were not written until:

- **Fix:** `OrderPromotionRecorder::entries_from_order_fees()` + `woocommerce_checkout_order_processed` fallback  
- **Evidence:** Backfill via WP-CLI; new orders should record without manual step  

---

## Related docs

- [BROWSER_QA_RUNBOOK.md](BROWSER_QA_RUNBOOK.md)
- [BETA_RELEASE_DECISION.md](BETA_RELEASE_DECISION.md)
- [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md)
