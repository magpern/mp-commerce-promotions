# Commerce Promotions 0.7.0 — release notes

## Added

- **`geo_country` promotion condition** — restricts a promotion to visitors
  whose request-time country, as supplied by Universal Geo Context, matches
  one or more configured ISO 3166-1 alpha-2 codes. This is independent of
  `billing_country`: it reflects where the visitor is detected to be right
  now, not an address the customer typed in, and there is no fallback
  between the two — a matching `billing_country` alone does not satisfy a
  `geo_country` condition. **Fails closed**: if the visitor's country cannot
  be resolved (Universal Geo Context inactive/unavailable, or metadata
  missing), the condition does not match rather than defaulting to allow.
  Includes admin Simple Rule Builder support and rule-validator coverage.
- No runtime dependency on Universal Geo Context's internal classes — the
  integration boundary is a single guarded function call, consistent with
  this plugin's existing pattern for optional companion-plugin metadata.

## Configuration / behavior

- **Email-capture coexistence contract documented**: the automatic
  "New Customer Welcome Discount" registration promotion is configured with
  `coupon_behavior = block_native`, so it never stacks with any native
  WooCommerce coupon (including the new `biopentra-storefront` email-capture
  welcome coupons, but also any other native coupon). See
  `docs/EMAIL_CAPTURE_COUPON_COEXISTENCE.md`.
  **This `block_native` setting is database configuration, not code shipped
  in this ZIP** — installing 0.7.0 does not itself change any environment's
  `coupon_behavior`. It was applied on DEV as a separate, explicit step and
  still requires a separate, explicit step on PROD before that environment's
  behavior changes.

## Unchanged

- Database schema remains **1.19.0** (no migration).

## Install

Deploy `mp-commerce-promotions` **0.7.0** / tag **`v0.7.0`**. Requires
Universal Geo Context active (and providing visitor country metadata) for
`geo_country` promotions to ever match — without it, such promotions
fail closed and never apply, which is expected, safe behavior rather than
an error.

Rollback: **0.6.0** / `v0.6.0`.

## Scope

DEV packaging and acceptance only. Does not authorize production deployment,
and does not itself change any environment's `coupon_behavior` configuration.
