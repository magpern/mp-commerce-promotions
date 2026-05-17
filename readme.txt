=== Commerce Promotions for WooCommerce ===
Contributors: magpern
Tags: woocommerce, promotions, discounts, coupons, vouchers
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rule-driven promotion engine for WooCommerce with conditions, actions, promotion codes, and operational admin tools. Early MVP — not production-complete.

== Description ==

Commerce Promotions for WooCommerce is a **generic** promotion engine for stores that need more structure than ad-hoc coupons alone. Promotions are defined with **conditions** (when they apply), **actions** (what discount to apply), and **restrictions** (guardrails), then evaluated against the cart at runtime.

This plugin is in **early development / MVP**. It is suitable for testing and internal pilots, not for claiming marketplace-ready or production-complete status without your own review.

**How discounts work today (MVP):** eligible promotions apply as **negative WooCommerce cart fees** (`percentage_discount`, `fixed_amount_discount`, `free_shipping` shipping offset, or `cheapest_item_discount` for BOGO-style cheapest-unit targeting) or add a **free gift cart line** (`free_gift_product` at zero price). Promotion codes are entered in the standard coupon field; matching codes use virtual coupon data with **zero** native coupon discount — fees/gifts come from this plugin.

**Promotion codes:** plain codes are **never stored**. Only a SHA-256 hash and last four characters are kept. **Generated batch codes** are shown **once** in admin (copy or CSV download); full codes cannot be recovered after you leave that screen.

For architecture, limitations, and development workflow, see the plugin repository and `docs/ARCHITECTURE.md`.

== Features ==

* Custom promotion tables (not stored as native WooCommerce coupons)
* Promotion lifecycle: draft, active, paused, archived
* Stackable promotions can apply multiple cart fees (capped at subtotal); exclusive/code-linked behavior preserved
* Promotion exclusion IDs — skip specific promotions when an earlier-selected promotion lists them (planner + cart)
* Max applications — limits selected promotions per cart evaluation plan (enforced in planner + cart)
* Declares compatibility with WooCommerce High-Performance Order Storage (HPOS)
* Rule evaluation pipeline with pluggable condition/action types (MVP set)
* Conditions: minimum subtotal, product/category quantity, product in cart, category in cart, exclude sale items, min/max cart quantity, logged in, first order, customer role, billing country, customer email domain, customer redemption count (logged-in metadata when available)
* Promotion-level excluded product/category IDs (schema 1.8.0) — narrow evaluation scope without removing cart lines
* Actions: percentage discount, fixed amount discount, free shipping (MVP fee offset), cheapest item discount (variation-aware BOGO groundwork via negative fee; optional sale exclusion), free gift product (adds cart line at zero price)
* Simple Rule Builder (one condition + one action, including cheapest item and free gift) and raw JSON rule editing
* Rule validation panel in admin
* WooCommerce admin: Promotions list with search, filters, and pagination
* Promotion edit workflow: status actions, duplicate as draft, cart preview with evaluation trace (admin/debug)
* Manual promotion codes (hashed at rest)
* Generated code batches (up to 1,000 per batch) with batch traceability
* Redemption tracking per order with idempotency and reversal on cancel/refund/trash
* Audit log for key promotion and code events
* Diagnostics and manual usage counter repair
* Reports tab — redemption summaries and CSV export (5,000 row cap; no raw promotion codes)
* Settings kill switch for cart discount application

== Current MVP limitations ==

* **Not marketplace-ready** — missing automated tests, formal uninstall data policy UI, Composer/CI packaging, and broader compatibility certification
* **Stacking rules** — exclusive (default) stops after one selection; stackable promotions can apply multiple fees (one action each; total discount capped at subtotal); code-linked promotions do not stack with automatic promotions
* **Cart fees only** — not line-item or native coupon discount strategies; may affect reporting/tax expectations
* **Limited condition/action types** — no automatic gift selection, variable-product UI, or native shipping-method control yet
* **free_gift_product** — MVP adds configured product IDs to cart at zero price; reversal does not remove gift lines from existing orders
* **cheapest_item_discount** — BOGO groundwork only (negative fee); does not add free products to the cart
* **Free shipping** — fee-offset MVP only; browser checkout required to confirm shipping totals
* **Simple Rule Builder** — one condition and one action only; complex multi-rule promotions need JSON
* **No REST/AJAX admin APIs** for rules or code search
* **Partial refunds** — reversal is all-or-nothing per order/promotion, not proportional
* **Generated codes** — full plaintext codes are show-once; not stored and not recoverable later
* **No PDF/email** voucher delivery
* **Diagnostics** — scans capped (100 promotions / 100 codes per run); repair is manual
* **Uninstall** — custom tables and options are **retained** by default (see uninstall.php)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/mp-commerce-promotions/` or install from your deployment process.
2. Activate **Commerce Promotions for WooCommerce** through the **Plugins** screen.
3. Ensure **WooCommerce** is installed and active.
4. Open **WooCommerce → Promotions** to create promotions and configure rules.
5. Optional: enable or disable cart discount application under **WooCommerce → Promotions → Settings**.

== Frequently Asked Questions ==

= Does this replace WooCommerce coupons? =

No. Native `shop_coupon` posts are not created for promotion codes. Customers still use the coupon field; the plugin validates known codes and applies discounts via cart fees.

= Are promotion codes stored in the database? =

Only a hash and the last four characters. Never store or log full codes in production.

= Can I recover generated batch codes later? =

No. Full codes are displayed once after generation. Download the CSV before leaving or refreshing the page.

= Does uninstall delete my data? =

**Not in the current MVP.** Uninstall intentionally retains custom tables and options to prevent accidental data loss. A future version may offer an explicit “delete all plugin data” setting.

= Is this ready for the WooCommerce Marketplace or WordPress.org? =

**No.** This release is foundational MVP software. Use readme.txt, LICENSE, and docs as scaffolding toward future distribution, not as a claim of readiness.

== Screenshots ==

1. Promotions list under WooCommerce (placeholder — add screenshots before public release).
2. Promotion edit screen with rule builder and validation (placeholder).
3. Generated code batch show-once screen (placeholder).

== Changelog ==

= 0.1.0 =
* Initial MVP: promotion engine, admin UI, cart fees, promotion codes, batches, redemptions, audit log, diagnostics.
* Marketplace/readiness scaffolding: readme.txt, LICENSE, uninstall placeholder, i18n bootstrap, documentation.
