# Commerce Promotions 0.6.0 — release notes

## Added

- **Bulk Pricing v1** — per-product percentage quantity brackets with a single
  line-pricing arbiter (`LinePricingArbiter`) as the sole `set_price()`
  committer. Includes product admin configuration and the storefront pricing
  contract used by `biopentra-storefront` 0.9.43+.
- **Universal Commerce Bundles compatibility** — cart rows marked
  `_ucb_component` are excluded from promotion eligibility context in
  `CartContextBuilder`. Kit parent lines remain subject to ordinary promotion
  rules. No runtime dependency on the UCB plugin classes.

## Unchanged

- Database schema remains **1.19.0** (no migration).
- Native WooCommerce coupons continue to apply outside the arbiter.

## Install

Deploy `mp-commerce-promotions` **0.6.0** / tag **`v0.6.0`** together with the
coordinated UCB and fulfillment releases when exercising kit carts on DEV.

Rollback: **0.5.4** / `v0.5.4`.

## Scope

DEV packaging and acceptance only. Does not authorize production deployment or
enabling bulk pricing / kits on live customer-facing SKUs.
