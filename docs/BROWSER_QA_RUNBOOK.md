# Browser QA runbook

Operational guide for certifying **Commerce Promotions for WooCommerce** before `0.2.0-beta.1`. Use with [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md) and [BROWSER_QA_MATRIX.md](BROWSER_QA_MATRIX.md).

**Plugin:** 0.1.0 · **Schema:** 1.14.0 · **Storefront default:** classic shortcode cart/checkout (`cart-2`, `checkout-2`).

---

## 1. Payment gateway setup

### Goal

Complete a real order in the browser (record → optional reversal) without crypto-only blockers.

| Option | When to use | Notes |
|--------|-------------|--------|
| **Cash on delivery (COD)** | Preferred on local/staging | Enable only on non-production |
| **BACS (direct bank transfer)** | Alternative offline | Often already installed with WooCommerce |
| **WooCommerce Payments / test gateway** | If installed | Use sandbox mode |
| **BTCPay** | Production reference only | **Not suitable** for quick QA — no instant “paid” without crypto flow |

### Local Docker (this project)

COD was enabled for QA via WP-CLI (2026-05-17):

```bash
cd /home/magpern/woocommerce
./wp eval '
$settings = get_option( "woocommerce_cod_settings", array() );
if ( ! is_array( $settings ) ) { $settings = array(); }
$settings["enabled"] = "yes";
update_option( "woocommerce_cod_settings", $settings );
'
./wp cache flush
```

**Admin path:** WooCommerce → Settings → Payments → **Cash on delivery** → Enable → Save.

**Verify:** Checkout shows “Cash on delivery”. **Disable COD** after QA on shared staging if policy requires.

### Production (`biopentra.eu`)

**Blocker:** BTCPay-only checkout for automated browser certification. Use COD/BACS on a staging clone, or complete order placement manually and verify redemptions in admin.

---

## 2. Required test products

Create or reuse products that exercise targeting and gift logic.

| Role | Requirements | Example (local Docker) |
|------|----------------|-------------------------|
| **Simple** | Published, in stock, known price | Any simple catalog product |
| **Variable** | At least one variation in stock | MOTS-C (3702) + variation 3703 |
| **On sale** | `sale_price` set | One simple SKU on sale |
| **Gift SKU** | Simple (not variable-only) | Dedicated low-value simple product for `free_gift_product` |
| **Categories** | Two categories for scoped tests | Assign products to `cat-a` / `cat-b` |

**Avoid:** Variable-only gift parents (Kisspeptin-style “choose options” noise).

---

## 3. Required test promotions

Create **one active promotion per row** (or use WP-CLI smoke seeds). Archive after certification to reduce clutter.

| Scenario | Action / rules | Pass criteria |
|----------|----------------|---------------|
| **Stacked fixed** | Two stackable `fixed_amount_discount`, stop processing off | Two negative fees; subtotal cap |
| **Scoped %** | `percentage_discount` + product/category scope | Fee matches scoped subtotal only |
| **Cheapest item BOGO** | `cheapest_item_discount` + scope | Fee on cheapest eligible unit |
| **Free shipping** | `free_shipping` | Shipping offset fee when shipping > 0 |
| **Free gift** | `free_gift_product` + threshold condition | Gift line at $0; sync on recalc |
| **Promotion code** | Code-linked promotion; pause automatic overlap | Code in coupon field; fee applies |
| **Budget limit** | Low `budget_amount`; exhaust via orders | Skipped when exhausted |
| **Cooldown** | `cooldown_hours` + prior redemption | Second cart skips with cooldown reason |
| **Orchestration** | Two actives, same `orchestration_group` | Only one winner per group |

---

## 4. Required customer accounts

| Persona | Setup | Tests |
|---------|--------|--------|
| **Guest** | Logged out | Session recording, no `customer_redemption_count` unless email captured |
| **Logged-in customer** | Subscriber/customer role | Redemption count, cooldown, per-customer limits |
| **Role-targeted** | User with specific role + `customer_role` condition | Eligible vs ineligible |

---

## 5. Browser checklist sequence

Run in order on **classic** cart (`/cart-2/`) and checkout (`/checkout-2/`) unless testing block pages (see [BLOCK_CHECKOUT_INVESTIGATION.md](BLOCK_CHECKOUT_INVESTIGATION.md)).

1. **Settings** — Cart discounts on; note safe mode off.
2. **Admin** — Promotions list loads; one active promo visible.
3. **Add to cart** — Simple product; open cart.
4. **Automatic promo** — Confirm negative fee line(s) and totals.
5. **Recalc** — Change qty; fees and gifts stay consistent.
6. **Coupon** — Apply promotion code (if testing codes); automatic promos should not stack unless designed.
7. **Checkout** — COD (or test gateway); place order.
8. **Order admin** — Redemption row(s), `_mp_cp_applied_promotions` meta, usage_count.
9. **Reports** — Filter by date; optional CSV export download.
10. **Reversal** — Cancel or refund order; usage_count and redemption status.
11. **Restore** (optional) — Move order back to processing; redemption restored.
12. **Diagnostics** — Dry-run repair; support bundle download (optional).
13. **Safe mode** — Enable; cart fees clear; disable after test.
14. **Block pages** (optional) — Draft pages `mp-cp-block-cart-qa` / `mp-cp-block-checkout-qa` (preview URL).

Record each step in [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md).

---

## 6. Evidence to capture

- Screenshot or note: cart fees, checkout total, order ID.
- WP-CLI: `./wp post meta list <order_id> | grep mp_cp`
- DB or admin: redemption row for `(order_id, promotion_id)`.
- Link to commit / date in [RELEASE_EVIDENCE_0.2.0_BETA1.md](RELEASE_EVIDENCE_0.2.0_BETA1.md).

---

## Related docs

- [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md)
- [BLOCK_CHECKOUT_INVESTIGATION.md](BLOCK_CHECKOUT_INVESTIGATION.md)
- [CART_CHECKOUT_BLOCKS_COMPATIBILITY.md](CART_CHECKOUT_BLOCKS_COMPATIBILITY.md)
- [manual-checkout-test.md](manual-checkout-test.md)
