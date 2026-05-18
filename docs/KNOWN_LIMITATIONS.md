# Known limitations

Registry source: `KnownLimitationsRegistry` (keyed by detection `code`).

## WooCommerce Subscriptions {#woocommerce-subscriptions}

Renewal and subscription carts are not certified. Automatic promotions may not apply on renewal flows.

**Mitigation:** Test subscription checkout and renewals before high-value campaigns.

## Product Bundles {#product-bundles}

Bundle parent/child line pricing may not match fee allocation breakdowns.

**Mitigation:** Prefer fee-based discounts; verify bundle carts manually.

## Composite Products {#composite-products}

Composite configuration lines may not receive expected scoped discounts.

**Mitigation:** Avoid line-item mode on composite catalogs until QA passes.

## Multi-currency {#multi-currency}

Promotion amounts are stored in shop base currency; converted display may drift.

**Mitigation:** Validate each currency before campaigns.

## Tax-inclusive {#tax-inclusive}

Reports use heuristic tax allocation; checkout tax plugins may differ.

**Mitigation:** Compare checkout totals to Reports summaries.

## Germanized {#germanized}

No integration with Germanized checkout fields or VAT ID flows.

**Mitigation:** Confirm B2B flows do not double-discount with native coupons.

## Dynamic pricing {#dynamic-pricing}

Third-party plugins may mutate line prices before/after promotion fees.

**Mitigation:** Test cart calculation order; use exclusive promotions when unsure.

## Memberships {#memberships}

Membership-gated pricing is not coordinated with promotion eligibility.

**Mitigation:** Add explicit customer/role conditions.

## Object cache {#object-cache}

Planner locks and compatibility snapshots depend on cache TTL and flush policies.

**Mitigation:** Run Diagnostics lock cleanup after deploys.

## HPOS {#hpos}

Plugin declares HPOS compatibility; other order plugins may still use posts table.

**Mitigation:** Complete WooCommerce compatibility review for all order extensions.

## Coupon plugins {#coupon-plugins}

Native or advanced coupon plugins may stack unpredictably with promotion fees.

**Mitigation:** Set `coupon_behavior` per promotion; test coexistence.

## Cart/Checkout Blocks {#cart-checkout-blocks}

Blocks use Store API; line-item unit display may not reflect server mutations.

**Mitigation:** Use fee-based mode for blocks unless line UI QA passes.
