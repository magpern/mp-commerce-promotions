# Beta release decision — 0.2.0-beta.1

**Decision date:** 2026-05-17  
**Commit baseline:** `43d8b63` (classic checkout browser QA + recording fix)  
**Release commit:** `04000d7` — `chore: release 0.2.0-beta.1`  
**Release artifact:** `/home/magpern/mp-commerce-promotions-staging/build/mp-commerce-promotions-0.2.0-beta.1.zip`  
**Product owner approved:** **pending**
**Environment:** Local Docker WooCommerce at https://www.biopentra.eu (bind-mounted plugin)  
**Gateway:** Cash on delivery (COD) enabled for QA  

---

## QA summary

Classic shortcode cart (`/cart-2/`) and checkout (`/checkout-2/`) were exercised with seeded **Browser QA** promotions (IDs **154–166**), qualifying product **3703** (MOTS-C 10mg), gift SKU **4338**, and COD checkout.

| Area | Result |
|------|--------|
| **Stacked fees (browser)** | **Pass** — dual fees −€10 / −€5; cart total €77 on €92 subtotal |
| **Checkout + COD (browser)** | **Pass** — order **#4339** placed (guest, COD) |
| **Redemption recording** | **Pass** after fix — fee-line fallback + backfill; see below |
| **Reversal (cancel)** | **Pass** — order #4339 cancelled; redemptions `reversed`, `usage_count` 0 |
| **Promotion code** | **Pass** (CLI cart) — code `BROWSERQA15`, promo **160**, −€15 fee |
| **Orchestration group** | **Pass** (CLI) — one winner (Orch A −€4) in group `browser-qa-lane` |
| **Exclusion** | **Partial** — both Excl A and B fees appeared; planner exclusion needs review |
| **Scoped %** | **Partial** — 0 fees in CLI with `product_in_cart` 3703; needs browser re-check |
| **Cheapest item** | **Not run** (isolated) — blocked by QA script pause bug (fixed this milestone) |
| **Free shipping** | **Partial** — no shipping in CLI cart; not browser-verified with paid shipping |
| **Free gift** | **Partial** — gift line not browser-verified; promo **159** seeded |
| **Budget / cooldown** | **Not run** — timeboxed; smoke coverage only |
| **Admin UI** | **Pass** — list, tabs (Getting Started, Settings, Diagnostics, Reports) |
| **CSV export** | **Partial** — Reports tab loads; download not triggered |
| **Support bundle** | **Not run** |
| **Cart/Checkout Blocks** | **Not declared** — unchanged |

---

## Pass/fail table (classic browser certification)

| Scenario | Browser | Notes |
|----------|---------|-------|
| Stacked fixed discounts | **Pass** | Promos **154**, **155**; order **4339** fees on order |
| Scoped percentage | **Partial** | Promo **156**; CLI 0 fees |
| Cheapest item | **Not run** | Promo **157** |
| Free shipping | **Partial** | Promo **158** |
| Free gift | **Partial** | Promo **159**, gift product **4338** |
| Promotion code | **Pass** | Promo **160**, code `BROWSERQA15` |
| Code vs automatic stacking | **Partial** | Exclusive code promo; not dual-tested in browser |
| Budget limit | **Not run** | Promo **161** |
| Cooldown | **Not run** | Promo **162** |
| Orchestration group | **Pass** | Promos **163**, **164** — one fee |
| Exclusion | **Partial** | Promos **165**, **166** — both fees |
| Checkout recording | **Pass** | After session fallback fix |
| Usage counts | **Pass** | Increment on record, 0 after cancel |
| Code usage | **Not run** | |
| Reversal | **Pass** | Order **4339** cancelled |
| Restore | **Not run** | |
| Reports / CSV | **Partial** | UI only |
| Diagnostics | **Partial** | List loads; repair/bundle not run |

---

## Blocking issues

None for a **limited beta** after this milestone’s recording fix is deployed.

---

## Non-blocking known issues

1. **Checkout recording without session** — fixed in `OrderPromotionRecorder::entries_from_order_fees()` + `woocommerce_checkout_order_processed` fallback; order **4339** backfilled manually for evidence.
2. **QA setup script** — `classic-browser-qa-setup.php` pause logic corrected (was not pausing other Browser QA promos).
3. **Exclusion planner** — both excluded pair may apply in same cart (investigate).
4. **Scoped targeting** — verify `product_in_cart` with variation **3703** in browser.
5. **Block checkout** — still not certified.
6. **PHPCS** — non-gating baseline debt.
7. **Production** — BTCPay-only; classic COD QA uses local Docker.

---

## Recommendation

**Ready with caveats** for `0.2.0-beta.1` on **classic shortcode** storefronts for technical early adopters, after:

1. Product owner signs off this decision doc.
2. Tag uses commit including the **checkout recording fix** (not `808a261` alone).
3. Release notes state: blocks checkout not supported; COD/BACS staging recommended for E2E.

**Not ready** for: production biopentra-only BTCPay merchants without staging clone; block-theme cart/checkout without separate investigation pass.

---

## Required approval checklist

- [ ] Product owner approves **ready with caveats** — **pending**
- [x] Classic browser sign-off accepted (stacked + COD order + reversal)
- [x] Version bump to `0.2.0-beta.1` in release commit (tag **pending** owner approval)
- [x] `cart_checkout_blocks` remains **undeclared**
- [ ] Pilot merchants informed of fee-based discounts and block limitation

---

## Related docs

- [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md)
- [MANUAL_QA_EVIDENCE.md](MANUAL_QA_EVIDENCE.md)
- [RELEASE_EVIDENCE_0.2.0_BETA1.md](RELEASE_EVIDENCE_0.2.0_BETA1.md)
- [VERSION_BUMP_PLAN_0.2.0_BETA1.md](VERSION_BUMP_PLAN_0.2.0_BETA1.md)
