# Browser QA matrix

Manual storefront and admin checks for MP Commerce Promotions.  
**Certification run:** 2026-05-17 (beta readiness milestone).

Legend: **Pass** | **Fail** | **Partial** | **Blocked** | **Not run**

## Storefront — classic (shortcode cart)

| Scenario | Status | Notes |
|----------|--------|-------|
| Stacked fees (multiple stackable) | **Partial** | WP-CLI stacking smokes; browser partial on biopentra.eu |
| Scoped discounts | **Not run** | Use scoped promotion + category/product scope |
| Cheapest item discount | **Partial** | `cheapest-item-smoke.php` pass; browser not re-run |
| Free gift | **Partial** | Active promo #79 on live; variable product noise in prior runs |
| Free shipping | **Partial** | Smoke scripts pass; browser not re-run |
| Promotion code (coupon field) | **Partial** | Code promos exist (paused smoke codes); field UI pass |
| Checkout recording | **Partial** | `checkout-integrity-smoke.php` pass; BTCPay blocks paid checkout |
| Reversal (cancel/refund) | **Partial** | Hook coverage in unit/smoke; browser cancel not run |
| Restore after re-pay | **Not run** | Document in manual-checkout-integrity-test |
| Subtotal cap | **Pass** | Smoke / unit coverage |
| Safe mode (no auto fees) | **Pass** | Settings + smoke verified 2026-05-17 |
| Guest checkout | **Not run** | |
| Logged-in checkout | **Partial** | Admin session used; customer flow not isolated |

## Storefront — Cart/Checkout Blocks

| Scenario | Status | Notes |
|----------|--------|-------|
| Block cart fees | **Blocked** | Local cart page is shortcode; see [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md) |
| Block checkout codes | **Blocked** | Not tested |
| Block free gift | **Blocked** | Not tested |
| Block order recording | **Blocked** | Not tested |
| **Declare `cart_checkout_blocks`** | **No** | Do not declare until block page checklist passes |

## Admin

| Scenario | Status | Notes |
|----------|--------|-------|
| Promotions list / filters | **Pass** | biopentra.eu 2026-05-17 evidence |
| Promotion edit / preview | **Pass** | Cart preview visible |
| Reports — summary + filters | **Pass** | |
| Reports — production hardening section | **Pass** | Added closure milestone |
| Reports — CSV export | **Partial** | Button present; download not triggered |
| Diagnostics — repair dry-run | **Partial** | UI pass; apply not run on production |
| Diagnostics — support bundle | **Not run** | |
| Settings — gates (telemetry, cron, safe mode) | **Pass** | |
| Getting Started tab | **Pass** | Commercial readiness milestone |

## Production safety

| Scenario | Status | Notes |
|----------|--------|-------|
| Safe mode | **Pass** | |
| Telemetry pause | **Pass** | Settings + smoke |
| Simulation pause | **Pass** | |
| Automation emergency stop | **Pass** | |
| Degraded storefront notice | **Not run** | |

## Reproducible QA carts

| Cart | Lines | Subtotal target |
|------|-------|-----------------|
| Small | 1 × simple product | ~€25–50 |
| Medium | 3 × mixed categories | ~€120 |
| Large | 10+ lines | €500+ |

## Test promotion presets

| Preset | Expected outcome |
|--------|------------------|
| 10% subtotal automatic | Negative fee ≈ 10% of subtotal |
| Fixed €5 off | Fee −5 (capped at subtotal) |
| Code-only promotion | Applies only when coupon entered |
| Exclusive + stackable pair | Exclusive stops further selection |
| Budget exhausted | Skipped with budget reason in plan |
| Safe mode ON | No automatic fees; codes optional if allowed |
