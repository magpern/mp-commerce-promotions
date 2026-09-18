# Email-capture welcome coupon coexistence (config change record)

Related feature: `biopentra-storefront` email-capture popup (10% off for an
email address, no account registration required). See
[COUPON_COMPATIBILITY.md](COUPON_COMPATIBILITY.md) for the general
`coupon_behavior` / `CouponCoexistenceEvaluator` mechanism this record uses.

## What changed

Promotion `id=1` ("New Customer Welcome Discount", conditions `logged_in` +
`first_order`, the automatic registration-based 10% fee) had
`coupon_behavior` changed:

```
coexist → block_native
```

No PHP was changed. `CouponCoexistenceEvaluator::evaluate_promotion()`
(`src/Woo/CouponCoexistenceEvaluator.php:64-107`) already implements
`block_native` correctly — verified during discovery for this feature: when
`coupon_behavior === BLOCK_NATIVE` and one or more native WooCommerce
coupons are applied to the cart, the evaluator returns `allowed: false,
reason: REASON_BLOCKED_BY_COUPON`, and `PromotionPlanner::plan()`
(`src/Engine/PromotionPlanner.php:95-96`) already calls this evaluator for
every promotion before applying it. This is existing, tested behavior, not
new code written for this feature.

## Why

The storefront now also issues single-use native `WC_Coupon`s (10% off,
`_bp_ecp_source` tagged) to visitors who submit just their email via a popup,
without registering. Frozen business decision: the automatic registration
10% must **never stack with any native WooCommerce coupon** — not only the
new email-capture coupon, but any native coupon (a support code, a seasonal
code, etc.). This is the broad rule, chosen because it reuses an existing,
already-tested mechanism rather than adding a new promotion-source-specific
condition, and because stacking two 10% offers (or a 10% offer with any
other native coupon) was never an intended outcome.

## Effect

- A logged-in, first-order customer with an **empty cart or no coupon
  applied**: automatic 10% fee still applies exactly as before this change.
- Same customer applies **any** native coupon (email-capture-issued or
  otherwise): the automatic 10% fee is skipped; only the native coupon's
  discount applies. Total discount is never both.

## How it was applied (DEV)

Applied headlessly via `wp eval`, using the plugin's own validated domain
layer rather than the browser admin UI or raw SQL: loaded promotion `id=1`
through `PromotionRepository::find(1)`, produced an updated copy via
`Promotion::with_pricing_fields(null, 'block_native', null, null)` — which
normalizes/validates the new value through `PromotionCouponBehavior::normalize()`,
the same validation the admin UI's POST handler (`src/Admin/PromotionEditPage.php`,
~line 1488, `PromotionCouponBehavior::is_valid()`) applies — and persisted it
with `PromotionRepository::update()`. Confirmed afterward with a direct
`wp db query` read (see Before/After below). **Not** applied via raw SQL,
and **not** applied by clicking through the browser admin UI.

### Before/after (DEV), captured via `wp db query`

**Before:**
```
id=1, name="New Customer Welcome Discount", status=active,
coupon_behavior=coexist, conditions=[{"type":"logged_in"},{"type":"first_order"}]
```

**After** (per the procedure above):
```
id=1, name="New Customer Welcome Discount", status=active,
coupon_behavior=block_native
```

## PROD configuration procedure (not yet applied)

This is a **data/config change, not a code migration** — it will not ship
via any plugin update or release ZIP. Before promoting the email-capture
feature to PROD, an operator must separately apply the same admin-UI change
on the PROD promotions database: Promotions → "New Customer Welcome
Discount" → Coupon behavior → "Block when native coupon applied" → Save.
This step is easy to miss because there is no code diff to remind anyone —
call it out explicitly in the PROD promotion runbook/checklist for this
feature.

## Rollback

Promotions → "New Customer Welcome Discount" → Coupon behavior → "Allow
coexistence" (back to `coexist`) → Save. No code rollback needed either way.
