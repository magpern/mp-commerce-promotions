# ADR-0001: Bulk pricing ownership and pricing model

## Status

Accepted — frozen with implementation plan rev 3.

## Context

Biopentra sells research peptides in vial quantities. Merchants need per-SKU volume breaks on **simple** WooCommerce products without bundle products, hidden components, or Universal Commerce Bundles integration.

`mp-commerce-promotions` v0.5.x has a campaign promotion engine (conditions, actions, planner, fee-based and line-item discounts) but no per-product quantity-tier pricing or PDP storefront contract. `biopentra-storefront` PDP purchase panel is hook-only (PDP-1 freeze).

Prior design risks identified in review:

1. Using `get_price()` on cart line objects after `set_price()` causes compounding discounts across totals cycles.
2. Promotion line applier mutating prices before arbitration prevents fair bulk-vs-promotion comparison.
3. Native WooCommerce coupons are not reliably per-line quotable for arbitration.
4. Exact-quantity tiers produce poor UX between anchor quantities.
5. Multicurrency `set_price()` currency is not assumed safe without empirical verification.

## Decision

### 1. Ownership

- **`mp-commerce-promotions`** owns configuration, validation, pricing engine, cart/checkout/order enforcement, cache invalidation, and versioned storefront contract.
- **`biopentra-storefront`** owns PDP rendering, CSS, JS, a11y, quantity sync, custom-qty preview, sticky-price sync only.
- **`biopentra-blocksy-child`** is not modified in v1.

### 2. Subsystem, not promotion rules

Bulk pricing is a dedicated `BulkPricing` namespace parallel to `GiftCard`. It does **not** use promotion JSON rules, `RuleRegistry`, or the campaign planner for tier configuration.

### 3. Bracket model

Each tier has `min_quantity` and `discount_percentage` (whole percent). Applied tier = highest where `line_qty >= min_quantity`. PDP anchor cards set suggested quantities; cart uses actual quantity.

### 4. Percentage tiers only (v1)

No fixed unit-price tiers. Avoids multicurrency fixed-price complexity. Fits 5% / 10% / 15% commercial model.

### 5. Pristine catalog base snapshot

**Class:** `BulkPricing\CatalogBasePriceResolver`

- Derive base only from fresh `wc_get_product( $product_id )`, **never** `cart_item['data']`.
- Capture once per `woocommerce_before_calculate_totals` cycle.
- **Do not** compare cart line price to snapshot for staleness re-capture.
- Snapshot fields: `product_id`, `base_unit_minor` (int), `currency`, `price_source` (`regular`|`sale`), `cycle_id`, `catalog_price_hash`.
- All quotes use snapshot minor units only.

### 6. Quote-then-commit; single commit point

```text
Capture pristine catalog base
→ quote bulk from snapshot
→ quote mp-cp line promotions from snapshot (no set_price)
→ choose min(bulk, promotion, standard); tie → bulk_tier
→ LinePricingArbiter: only set_price() caller for arbitrated lines
```

`LineItemDiscountApplier` refactored to quote-only via `PromotionLineQuoteService` before bulk implementation (gate A0).

### 7. Arbitration scope

- **In scope:** bulk tier vs mp-commerce-promotions line-targeting promotion allocation on the same line.
- **Out of scope:** native WooCommerce coupons (existing WC behaviour; compatibility-tested separately).

### 8. Effective selling price

Percentage discount applies to active WooCommerce effective price (sale when on sale). Never tier from fictitious regular price. No auto “Save X%” or crossed-out reference prices in v1.

### 9. Safety overrides

| Switch | Bulk pricing |
|--------|--------------|
| `mp_cp_bulk_pricing_enabled` | Master on/off |
| `mp_cp_safe_mode` | Suppress application |
| `mp_cp_promotion_dry_run` | No effect |
| `mp_cp_cart_discounts_enabled` | No effect |

### 10. Multicurrency

Canonical amount and currency for `set_price()` determined by **A-1 spike** ([`docs/spikes/umc-bulk-pricing-set-price.md`](../spikes/umc-bulk-pricing-set-price.md)). Implementation blocked until spike passes. Integer minor units for arithmetic.

### 11. Storefront contract

`mp_cp_bulk_pricing_storefront_v1` filter. Minor units + formatted strings. `bracket_table` for custom-qty JS preview (display only). Null → storefront renders nothing.

### 12. Caching

Never `wp_cache_flush()`. Epoch bump + targeted transient delete. Keys include product ID, epoch, **currency**, price hash, config hash.

## Consequences

- Reuses WooCommerce order/refund/tax/stock for simple products.
- Requires promotion line applier refactor (A0) before bulk cart pricing.
- Merchants cannot stack bulk tier + line promotion on same SKU; lower price wins.
- PDP price may differ from cart when mp-cp promotion wins (disclaimer required).
- UMC behaviour must be verified, not assumed.

## Rejected alternatives

- Promotion JSON rules per tier — admin burden, no PDP contract, planner conflicts.
- `get_price()` on cart line as calculation base — compounding risk.
- Exact-quantity-only tiers — poor UX at qty 4 between 3 and 5 anchors.
- Fixed unit price tiers v1 — UMC complexity.
- WC coupon in arbitration — not reliably per-line quotable.
- Priority-10 restore-to-base step — unnecessary if quotes use snapshot only; contradicts single commit semantics when misapplied to cart object reads.
- `wp_cache_flush()` on global toggle — site-wide collateral damage.
