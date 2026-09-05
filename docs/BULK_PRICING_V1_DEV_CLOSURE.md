# Bulk Pricing v1 — DEV milestone closure

**Status:** PASS (DEV post-merge confirmation)  
**Closure date:** 2026-09-05 (UTC)  
**Environment:** https://dev.biopentra.eu  
**Production:** not deployed · no release tag · no release artifact · no live SKU enabled

---

## Source control

| Item | Value |
|------|--------|
| Architecture freeze PR | [mp-commerce-promotions#2](https://github.com/magpern/mp-commerce-promotions/pull/2) |
| Freeze merge SHA | `29607849db88a57b391b92d7f04f0b8c8973c54f` |
| Implementation PR (engine) | [mp-commerce-promotions#3](https://github.com/magpern/mp-commerce-promotions/pull/3) |
| Engine merge SHA | `572c8a659917290198f701c8e96a31710aa8f1d8` |
| Storefront PR | [biopentra-custom-plugins#37](https://github.com/magpern/biopentra-custom-plugins/pull/37) |
| Storefront merge SHA | `420b73f4a634b0dc5cedf10331530ba7bfac9588` |
| Acceptance PR | [storefront-acceptance#5](https://github.com/magpern/storefront-acceptance/pull/5) |
| Acceptance merge SHA | `a708122b507d3a109552c9486149bcf6ea2c4d2c` |

### DEV checkout revisions at post-merge confirmation

| Checkout | Branch | HEAD |
|----------|--------|------|
| `mp-commerce-promotions` | `main` | `9d9d8a18ccedd793fb77d7b8da803d01dd3be8d5` (includes engine merge + later UCB work) |
| `biopentra-custom-plugins` / storefront | `main` | `420b73f4a634b0dc5cedf10331530ba7bfac9588` |
| `storefront-acceptance` | `main` | `a708122b507d3a109552c9486149bcf6ea2c4d2c` |

Working trees were clean (no staged/uncommitted/branch-only bulk-pricing code).

---

## Runtime on DEV

| Check | Result |
|-------|--------|
| `mp-commerce-promotions` | active **0.5.4** |
| `biopentra-storefront` | active **0.9.43** |
| `mp_cp_bulk_pricing_enabled` | `yes` |
| Fixture product | ID **6902**, slug `bulk-pricing-fixture`, catalog visibility **hidden** |
| Products with bulk tiers enabled | **1** (fixture only) |
| Real / live SKUs with bulk config | **none** |

Fixture brackets: 1 / 3+ / 5+ / 10+ at 0% / 5% / 10% / 15%.

---

## Post-merge DEV acceptance

| Gate | Result |
|------|--------|
| `scripts/run-bulk-pricing-acceptance.sh` | **PASS** |
| Cart acceptance | 12/12 OK (two consecutive totals identical; brackets; coupons; compounding guard) |
| Promotion-wins acceptance | 5/5 OK |
| Playwright `tests/pdp-bulk-pricing.spec.ts` (`desktop-1440` + `mobile-390`) | **7/7 PASS** |
| UMC SEK path (PDP `?currency=SEK` → cart → checkout) | **PASS** |
| Control-product regression (no bulk markup) | **PASS** |
| Sticky price sync (mobile) | **PASS** |
| WCAG 2.2 AA axe on fixture selector | **PASS** |

Behaviour matched the previously accepted feature-branch run. No divergence requiring stop/rollback.

---

## UMC canonical-currency rule (binding)

| Concern | Rule |
|---------|------|
| Snapshot / quote | Display-currency minor units from a fresh `wc_get_product()->get_price()` |
| `set_price()` commit | Shop **base currency** via `GiftCardStorefrontAmounts::base_amount_from_display()` |
| Compounding prevention | Never read `cart_item['data']->get_price()` for base capture |

Evidence: [docs/spikes/umc-bulk-pricing-set-price.md](spikes/umc-bulk-pricing-set-price.md); CLI second-pass identity + Playwright SEK checkout.

---

## Scope boundaries (v1)

- **In scope:** simple products; percentage quantity brackets; `LinePricingArbiter` sole `set_price()` committer; storefront PDP selector + sticky sync; native WooCommerce coupons outside arbitration (coexist as cart discounts).
- **Out of scope / deferred:** variable/grouped products; fixed-amount brackets; automatic “Save X%” badge generation; production deploy; release tagging; real SKU enablement; `biopentra-blocksy-child` changes.

Architecture freeze: [docs/plans/BULK_PRICING_ARCHITECTURE.md](plans/BULK_PRICING_ARCHITECTURE.md), [docs/adr/0001-bulk-pricing-ownership-and-pricing-model.md](adr/0001-bulk-pricing-ownership-and-pricing-model.md).

---

## Deferred work (explicit)

1. Production rollout decision and release packaging (not started).
2. Enabling bulk pricing on any real/live SKU (not started; fixture-only on DEV).
3. Expanding product-type / discount-type coverage beyond v1 boundaries.
4. Adding automated CI for `biopentra-storefront` (DEV acceptance remains the effective verification gate for storefront).

---

## Closure statement

Bulk Pricing v1 is **implementation-complete and DEV-confirmed on merged `main`**. Milestone closed for development acceptance. Production release remains a separate, explicit decision.
