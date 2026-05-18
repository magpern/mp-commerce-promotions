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

## Storefront — line discount modes (experimental)

| Scenario | Status | Notes |
|----------|--------|-------|
| Line % on simple product | **Not run** | See [manual-line-discount-engine-test.md](manual-line-discount-engine-test.md) |
| Line fixed on simple product | **Not run** | |
| Hybrid fee fallback | **Not run** | |
| Tax-inclusive catalog | **Not run** | |
| Line + free gift combo | **Not run** | |

## Storefront — Cart/Checkout Blocks

Runbook: [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md). Evidence: [BLOCKS_QA_EVIDENCE_2026-05-18.md](BLOCKS_QA_EVIDENCE_2026-05-18.md).

| Scenario | Status | Notes |
|----------|--------|-------|
| Fee-based % / fixed in block cart | **Partial** | CLI fees OK; SSR fixed 2026-05-16 (full block markup on 4333) — browser re-test pending |
| Stacked fees | **Blocked** | QA promos exclusive |
| Promotion code (block coupon UI) | **Partial** | SSR fixed on 4334; browser re-test pending |
| Free shipping fee offset | **Partial** | CLI: no offset fee observed |
| Free gift add/remove | **Partial** | CLI Pass (gift $0) |
| Line item mode (block cart prices) | **Partial** | Not verified in browser |
| Hybrid fallback | **Not run** | |
| Checkout recording / redemptions | **Partial** | CLI Pass order 4342 |
| Reversal | **Partial** | CLI Pass on cancel |
| Native coupon coexistence | **Not run** | |
| Guest checkout (blocks) | **Blocked** | |
| Logged-in checkout (blocks) | **Not run** | |
| Hook audit (WP_DEBUG + option) | **Partial** | No logger output; hooks OK via cart/order tests |
| **Declare `cart_checkout_blocks`** | **No** | `block_compatibility_status` = **partial** |

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
