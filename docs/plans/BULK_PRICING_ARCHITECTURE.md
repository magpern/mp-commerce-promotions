# Bulk Pricing — Planning & Architecture (rev 3, frozen)

**Status:** Frozen — binding for implementation  
**DEV milestone:** Closed — see [BULK_PRICING_V1_DEV_CLOSURE.md](../BULK_PRICING_V1_DEV_CLOSURE.md)  
**Freeze branch:** `docs/bulk-pricing-architecture-freeze`  
**Related ADR:** [ADR-0001](../adr/0001-bulk-pricing-ownership-and-pricing-model.md)

## Purpose

Add per-product **quantity-bracket percentage pricing** for selected **simple** WooCommerce products. PDP anchor cards may be 1 / 3 / 5 / 10; cart quantity 4 receives the 3+ tier; quantity 11 receives the 10+ tier.

Example:

| Bracket (`min_quantity`) | Discount |
|--------------------------|----------|
| 1+ | 0% (effective selling price) |
| 3+ | 5% off effective price |
| 5+ | 10% off effective price |
| 10+ | 15% off effective price |

## Ownership

| Component | Owner |
|-----------|-------|
| Configuration, validation, pricing, cart/checkout/order enforcement, cache invalidation, storefront contract | `mp-commerce-promotions` |
| PDP selector UI, CSS, JS, a11y, quantity sync, custom-qty preview, sticky-price sync | `biopentra-storefront` |
| Sticky bar markup | `biopentra-blocksy-child` (unchanged in v1) |

No bundle products, variations, hidden components, or UCB integration.

## Product scope and enablement

- **Simple products only** in v1.
- Exclude variable products, gift cards, subscriptions, UCB/bundle lines.
- Global master: `mp_cp_bulk_pricing_enabled` (default off).
- Per-product enable + repeatable bracket rows: `min_quantity`, whole-number `discount_percentage`, optional `anchor_quantity`, optional merchant badge, display order.
- **Percentage tiers only** in v1. No fixed unit-price tiers. No global default tiers.
- `mp_cp_safe_mode` suppresses bulk application.
- `mp_cp_promotion_dry_run` and `mp_cp_cart_discounts_enabled` do **not** control bulk pricing.

## Effective selling price

- Tiers apply to the **active WooCommerce customer price** (including sale price).
- Never calculate from artificial regular/reference price.
- No crossed-out “was” prices or automatic “Save X%” badges in v1.
- Pilot badges: neutral text only (e.g. “Best value”).
- Merchant “Save” wording disabled unless separately approved for compliance.

## Quantity brackets

- Highest bracket where `line_quantity >= min_quantity` wins.
- PDP cards set anchor quantities; manual qty resolves to applicable bracket.
- Custom quantity shows applicable unit price + preview total; **server cart is authoritative**.

## Pricing pipeline (mandatory single commit point)

For every totals cycle:

```text
Resolve pristine catalog effective base price
→ capture immutable per-line snapshot
→ quote bulk pricing from snapshot
→ quote mp-commerce-promotions line promotions from same snapshot
→ choose lower of bulk / promotion / standard (tie → bulk_tier)
→ LinePricingArbiter performs the only final set_price() call
```

Rules:

- Never use `cart_item['data']->get_price()` as base-price source.
- Never use mutated cart-line price to detect staleness.
- Quote services are side-effect free.
- Only `LinePricingArbiter` calls `set_price()` for arbitrated lines.
- Persist applied source, bracket, percentage, base snapshot, final unit price on order line.

### Hook priorities (`woocommerce_before_calculate_totals`)

| Priority | Step |
|----------|------|
| 5 | `CatalogBasePriceResolver` — capture snapshot from fresh `wc_get_product()` |
| 12 | Bulk quote |
| 14 | Promotion quote (`PromotionLineQuoteService`) |
| 16 | Arbiter choose winner |
| 18 | Arbiter commit `set_price()` once per line |

**No restore step.** No re-capture from cart object.

## Promotion and coupon interaction

- Bulk vs **mp-commerce-promotions line-targeting** promotions: mutually exclusive per line; quote both from same snapshot; lower line total wins; tie → `bulk_tier`.
- **Native WooCommerce coupons:** outside arbitration; preserve existing WC behaviour; compatibility tests required.
- Free shipping and unrelated cart benefits unchanged.

## Multicurrency (A-1 gate)

Before bulk cart implementation, complete spike documented in [`docs/spikes/umc-bulk-pricing-set-price.md`](../spikes/umc-bulk-pricing-set-price.md).

- Integer **minor units** for all bulk calculations.
- Canonical currency for `set_price()` determined by A-1 — not assumed.
- Contract cache keys include active currency/context.

## Storefront contract

Filter: `mp_cp_bulk_pricing_storefront_v1`

Includes: currency, decimals, `base_unit_minor`, `bracket_table`, `anchors`, formatted HTML strings, `cache_version`.

Storefront must not recompute discounts. Custom qty may multiply supplied `unit_minor × qty` for **display preview only**.

Returns `null` when plugin inactive, disabled, invalid, or product ineligible → no markup, no assets.

## Caching

- **Never** `wp_cache_flush()`.
- Targeted: product transients, bulk-pricing transient delete, `mp_cp_bulk_pricing_cache_epoch` bump.
- Contract key: `mp_cp_bp_v1_{product_id}_{epoch}_{currency}_{price_hash}_{config_hash}`.
- Purge affected PDPs on config change, sale schedule change, global toggle.

## Implementation work packages

| ID | Package | Gate |
|----|---------|------|
| A-1 | UMC pricing spike | **Yes — first** |
| A0 | Quote-only promotion refactor | **Yes** |
| A1 | Domain, meta, cache invalidation | |
| A2 | Calculator + storefront contract | |
| A3 | Catalog resolver + cart hooks | |
| A4 | LinePricingArbiter | **Yes** |
| A5 | Admin UI + global setting | |
| B1 | Storefront PDP module + assets | |
| B2 | Playwright acceptance | |

**Merge order:** A-1 → A0 → A1 → A2 → A3 → A4 → A5 → B1 → B2

## Suggested file layout

### mp-commerce-promotions

- `src/BulkPricing/CatalogBasePriceResolver.php`
- `src/BulkPricing/BulkPricingConfig.php`
- `src/BulkPricing/BulkPricingCalculator.php`
- `src/BulkPricing/BulkPricingProductMeta.php`
- `src/BulkPricing/BulkPricingStorefront.php`
- `src/BulkPricing/BulkPricingCacheInvalidator.php`
- `src/Service/PromotionLineQuoteService.php`
- `src/Service/LinePricingArbiter.php`
- `src/Woo/BulkPricingCartHooks.php`
- `src/Woo/BulkPricingProductAdmin.php`

### biopentra-storefront

- `modules/pdp-bulk-pricing/class-pdp-bulk-pricing-module.php`
- `assets/css/pdp-bulk-pricing.css`
- `assets/js/pdp-bulk-pricing.js`
- `assets/js/pdp-bulk-pricing-sticky-sync.js`

## Acceptance criteria

- [ ] Bracket: qty 4 → 3+ tier; qty 11 → 10+ tier
- [ ] Sale price as base
- [ ] Two `calculate_totals()` passes identical (no compounding)
- [ ] Catalog resolver ignores mutated `cart_item['data']` price
- [ ] Bulk / promotion / standard arbitration; tie → bulk
- [ ] No pre-arbiter `set_price()` on arbitrated lines
- [ ] WC coupon compatibility (not arbitration)
- [ ] UMC non-base currency per A-1
- [ ] Cache epoch + currency in keys; no global flush
- [ ] Playwright: anchor, custom qty, promotion-wins, sticky sync, a11y, regression
