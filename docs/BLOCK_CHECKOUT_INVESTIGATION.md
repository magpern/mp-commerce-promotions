# Block checkout investigation setup

**Purpose:** Prepare and execute Cart/Checkout **Blocks** QA without declaring `cart_checkout_blocks` until critical paths pass.

**Related:** [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md)

---

## Current storefront state

| Page | ID | Slug | Content | Role |
|------|-----|------|---------|------|
| Cart | **82** | `cart-2` | `[woocommerce_cart]` shortcode | **Live storefront** |
| Checkout | **83** | `checkout-2` | `[woocommerce_checkout]` shortcode | **Live storefront** |

WooCommerce Blocks package is **installed**; merchant cart/checkout remain **classic**.

---

## Local QA block pages (draft)

Created for investigation only — **not** assigned as WooCommerce cart/checkout pages.

| Page | ID | Slug | Content | Status |
|------|-----|------|---------|--------|
| MP CP Block Cart (QA) | **4333** | `mp-cp-block-cart-qa` | `<!-- wp:woocommerce/cart /-->` | **draft** |
| MP CP Block Checkout (QA) | **4334** | `mp-cp-block-checkout-qa` | `<!-- wp:woocommerce/checkout /-->` | **draft** |

**Preview (logged-in admin):**  
`https://<site>/cart/?page_id=4333` or publish temporarily and use `/mp-cp-block-cart-qa/`.

**Do not** set `woocommerce_cart_page_id` / `woocommerce_checkout_page_id` to these IDs on production.

---

## Steps to run block investigation

1. Publish draft pages **or** preview as admin (draft preview).
2. Enable **COD** on local/staging ([BROWSER_QA_RUNBOOK.md](BROWSER_QA_RUNBOOK.md)).
3. Add simple product to cart via block cart UI.
4. Confirm **negative fee** appears in block cart totals (Store API / cart recalc).
5. Apply **promotion code** in block coupon UI; confirm virtual coupon path.
6. Confirm **free gift** line (if testing) — quantity and $0 price.
7. Complete **block checkout** with COD.
8. Verify **order meta** and **redemption rows**.
9. **Cancel order** — verify reversal.
10. Note **admin/degraded notices** on block routes.

Record each row in a table (append to [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md)).

---

## Decision rule: declare `cart_checkout_blocks`?

Declare compatibility in `WooCompatibility::declare_feature_compatibility()` **only if all** are **Pass**:

| # | Critical path |
|---|----------------|
| 1 | Automatic promotion fees visible in block cart/checkout totals |
| 2 | Promotion codes work (fee applies; native discount 0) |
| 3 | `woocommerce_checkout_create_order` records redemptions |
| 4 | Reversal on cancel/refund |
| 5 | Free gift sync acceptable (no duplicate/orphan lines) |
| 6 | No fatal errors with 2+ active promotions |

If **any** is Fail or untested → **do not declare**; keep `CompatibilityStatus::cart_checkout_blocks_declared = false`.

---

## Block investigation status (2026-05-17)

| Check | Status |
|-------|--------|
| Block QA pages created | **Pass** (IDs 4333, 4334, draft) |
| Block cart fees | **Not run** |
| Block codes | **Not run** |
| Block checkout record | **Not run** |
| Block reversal | **Not run** |
| **Declare compatibility** | **No** |

---

## Making block pages the live cart (optional, staging only)

```bash
# Example only — staging disposable site
./wp option update woocommerce_cart_page_id 4333
./wp option update woocommerce_checkout_page_id 4334
# Revert when done:
./wp option update woocommerce_cart_page_id 82
./wp option update woocommerce_checkout_page_id 83
```

---

## Related docs

- [BROWSER_QA_RUNBOOK.md](BROWSER_QA_RUNBOOK.md)
- [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md)
