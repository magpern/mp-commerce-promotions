# Campaign Builder QA evidence

**Pass:** 2026-05-16  
**Base commit (Phase 3):** `7e4d44f`  
**QA commit:** `d31dbb8`  
**Default entrypoint commit:** `b0efa36`  
**Pilot release:** `0.3.0-pilot.2` (see `docs/PILOT_RELEASE_0.3.0_PILOT2.md`; pilot.1 superseded)  
**Environment:** WooCommerce Docker (`/home/magpern/woocommerce`), plugin synced from staging.

## Method

Each goal was exercised end-to-end via `CampaignBuilderDraftCreator` (same rules engine path as the admin wizard review step). Checks:

- Draft created (`status = draft`)
- No auto-activation
- Conditions/actions match goal template
- `CampaignSummaryFormatter` headline and review sections
- Advanced Editor URL shape (`tab=all&promotion={id}`)
- Coupon code shown once via transient pattern (coupon goal)

Manual admin UI spot-check: wizard progress, Continue/Back navigation, campaign name on offer step for non-targeting goals, localized picker strings, session-expired notice when `cb_token` transient missing.

## Results

| Goal | Result | Promotion ID | Notes |
|------|--------|----------------|-------|
| Category discount | **pass** | 802 | `percentage_discount` on category scope; draft; summary headline OK |
| Product discount | **pass** | 803 | Product scope; draft |
| Buy X get Y | **pass** | 804 | `cheapest_item_discount`; summary no duplicate scope clause after formatter fix |
| Free shipping | **pass** | 805 | `free_shipping` + minimum subtotal condition |
| Free gift | **pass** | 806 | `free_gift_product`; gift product ID 4356 |
| First order | **pass** | 807 | `first_order` condition; headline avoids duplicate “on first-time buyers” |
| VIP / role | **pass** | 808 | Role condition; role label uses translated role name |
| Coupon code | **pass** | 809 | Generated code `6ELD5TCDD7`; one-time transient; not auto-active |
| Budgeted | **pass** | 810 | Budget + usage limit on draft |
| Scheduled | **pass** | 811 | Start/end dates stored; category scope |

**Totals:** 10 pass / 0 fail / 0 partial

## Per-goal verification detail

### Shared (all goals)

| Check | Status |
|-------|--------|
| Wizard completes to draft | pass |
| Draft fields (name, label, schedule where set) | pass |
| Generated conditions/actions | pass |
| Summary text (formatter) | pass |
| Advanced edit link | pass |
| Coupon shown once (if applicable) | pass (coupon goal only) |
| No auto-activation | pass |

### Category discount (802)

- **Conditions:** category in cart / eligible subtotal as configured  
- **Actions:** percentage discount scoped to categories  
- **Summary:** e.g. “Customers get 10% off {categories}.”

### Product discount (803)

- Product IDs in scope; percentage/fixed action on products.

### Buy X get Y (804)

- BOGO benefit sentence includes scope once (no trailing “on {scope}” duplicate).

### Free shipping (805)

- Minimum subtotal condition + free shipping action.

### Free gift (806)

- Subtotal threshold + free gift line action.

### First order (807)

- First-order condition; headline: “First-time customers get …” without repeated audience phrase.

### VIP / role (808)

- Customer role targeting; display name “Customer” (not raw slug).

### Coupon code (809)

- Code generated at create; success screen reads `mp_cb_code_{user}_{promotion_id}` transient once.

### Budgeted (810)

- `budget_amount` and `usage_limit` persisted on promotion.

### Scheduled (811)

- `starts_at` / `ends_at` set; category-scoped discount.

## UX / accessibility fixes in this pass

- Summary formatter: BOGO, first order, VIP role copy  
- Campaign name on offer step when goal skips targeting  
- Wizard session expired warning when `cb_token` missing  
- Review step draft-only note  
- Progress `aria-current="step"`  
- Picker labels `for` + manual ID help; localized AJAX empty/error strings  
- Clearer invalid-nonce message  
- Primary step button label “Continue”

## Automated verification

```bash
cd /home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions
composer run lint:php
composer run test
cd /home/magpern/woocommerce
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-builder-smoke.php
```

## Merchant entrypoint (post-QA)

- WooCommerce → **Promotions** opens **Campaign Builder** when `tab` is omitted.
- **Advanced Promotions** (`tab=all`) remains for expert mode (raw rules, orchestration, diagnostics).
- **Create campaign** shortcuts on Getting Started, Advanced Promotions, Reports, and Diagnostics (when no promotions exist).

## Known limitations (unchanged)

- No new engine rules beyond existing templates  
- Wizard state: 1-hour transient per user/token; long idle sessions need restart  
- AJAX search requires `manage_woocommerce`; no front-end picker  
- Advanced orchestration, JSON rules, and diagnostics only in Advanced Editor  
- Product/category pickers need WooCommerce catalog data for meaningful search results
