# Browser QA matrix

**Certification run:** 2026-05-17 (classic checkout beta QA).

Legend: **Pass** | **Fail** | **Partial** | **Blocked** | **Not run**

## Storefront — classic (shortcode cart)

| Scenario | Status | Notes |
|----------|--------|-------|
| Stacked fees (multiple stackable) | **Pass** | Browser cart + order **4339**; promos **154**, **155** |
| Scoped discounts | **Partial** | Promo **156**; CLI 0 fees |
| Cheapest item discount | **Not run** | Promo **157** |
| Free gift | **Partial** | Promo **159**, gift **4338** |
| Free shipping | **Partial** | Promo **158**; CLI only |
| Promotion code (coupon field) | **Pass** | CLI −€15; code `BROWSERQA15` / **160** |
| Checkout recording | **Pass** | Fee fallback fix; order **4339** |
| Reversal (cancel/refund) | **Pass** | Order **4339** cancelled |
| Restore after re-pay | **Not run** | |
| Orchestration (one per group) | **Pass** | CLI Orch A −€4 |
| Exclusion behavior | **Partial** | Both exclusion promos applied in CLI |
| Budget / cooldown | **Not run** | |
| Subtotal cap | **Pass** | Smoke / stacked run |
| Safe mode | **Pass** | Prior milestone |
| Guest checkout | **Pass** | Order **4339** guest + COD |
| Logged-in checkout | **Partial** | Admin session on cart earlier; not isolated |

## Storefront — Cart/Checkout Blocks

| Scenario | Status | Notes |
|----------|--------|-------|
| Block cart fees | **Blocked** | Draft pages **4333**, **4334** not exercised |
| Block checkout codes | **Blocked** | |
| Block free gift | **Blocked** | |
| Block order recording | **Blocked** | |
| **Declare `cart_checkout_blocks`** | **No** | |

## Admin

| Scenario | Status | Notes |
|----------|--------|-------|
| Promotions list / filters | **Pass** | 148 promotions; Browser QA rows visible |
| Promotion edit / preview | **Partial** | List + edit links; deep edit not re-run |
| Reports — summary + filters | **Pass** | Tab navigation |
| Reports — CSV export | **Partial** | Button not clicked |
| Diagnostics — repair dry-run | **Partial** | Not run |
| Diagnostics — support bundle | **Not run** | |
| Settings — gates | **Pass** | Prior + cart discounts on |
| Getting Started tab | **Pass** | Prior milestone |

## Production safety

| Scenario | Status | Notes |
|----------|--------|-------|
| Safe mode | **Pass** | |
| COD QA gateway (local) | **Pass** | Enabled for run |
| BTCPay production E2E | **Blocked** | |
