# Universal Commerce Bundles component-line exclusion (v1.1 planning)

**Plugin:** MP Commerce Promotions `0.5.3` · **Status:** Frozen — Accepted per [ADR-0001](adr/0001-ucb-component-cart-line-exclusion.md)
**Scope:** planning + one narrow future implementation work package (this document does not implement anything)
**Implementation status:** production guard + PHPUnit builder-level tests + the mandatory live Store API/Blocks acceptance gate below are all complete on `feature/ucb-component-cart-exclusion` (see that branch's PR for the live-acceptance evidence and results below).

This repository has no active Pilot Stabilization Policy today (unlike the
sibling `mp-commerce-fulfillment` repository, which does). This is ordinary
`v1.1` roadmap work, gated on normal Product Owner approval of ADR-0001 —
not an exception to a frozen-pilot line.

## Problem

Universal Commerce Bundles ("UCB") Architecture B represents one kit
purchase as **one priced kit-parent** cart/order line plus **real,
zero-priced component-child** cart/order lines — one per component.
`mp-commerce-promotions`'s condition/discount engine has no awareness of
this shape today: nothing stops a hidden, zero-priced child line from
independently satisfying a `product_in_cart` / `category_in_cart` /
quantity condition, or from being picked as a discount target. UCB's own
`docs/adr/0007-cross-cutting-cart-order-line-exclusion-contract.md` names
this exact leak against "a companion promotions plugin's condition engine"
and documents the marker it exposes for that plugin to consume — the
consuming side has never been designed until now.

## Fixed architectural intent

- Component child lines are non-promotional implementation detail: they
  must not qualify a promotion by product, category, quantity, or
  equivalent cart-condition logic; they must not receive a discount; they
  must not inflate promotion thresholds, "buy X" counts, or eligibility
  context.
- A standalone purchase of the same component product continues to
  participate normally.
- The priced kit parent follows existing normal promotion rules — no new
  automatic "kits cannot be promoted" policy.
- This is a data-contract integration only. Promotions never loads, calls,
  detects, or depends on UCB code, classes, hooks, constants, autoloaders,
  activation state, or version.

## Evidence

### UCB side — the exact cart-level contract (`universal-commerce-bundles`)

- `src/Domain/MetaKeys.php:76` — `LINE_COMPONENT = '_ucb_component'`, "the
  load-bearing exclusion key (ADR-0007)", cart-item **and** order-item meta
  on a child line.
- `src/Woo/CartConstruction.php:80-92` — child lines are added via
  `$cart->add_to_cart($componentId, $qty, $variationId, [], ['_ucb_component' => 1, '_ucb_parent_item_id' => $parentKey, ...])`.
  WooCommerce merges `$cart_item_data` keys directly into the cart item's
  top-level array, so at cart stage `$cart_item['_ucb_component']` is a
  plain truthy array key — **not** `WC_Order_Item::get_meta()`. The
  order-item meta of the same name only exists post-checkout (WooCommerce
  auto-persists cart-item-data keys as order-item meta at that point) — a
  different call shape, confirmed distinct in UCB's ADR-0004 (the
  fulfillment-side counterpart reads `$item->get_meta('_ucb_kit', true)`,
  an order-item accessor, on a *different* key).
- A standalone add-to-cart of the same product never sets this key —
  confirmed by `CartConstruction::maybeAddChildren()` only writing it when
  called internally from the kit fan-out (`CartConstruction.php:38-52`).
- No custom `woocommerce_get_cart_item_from_session` handling in UCB for
  these keys — WooCommerce's default session serialization round-trips
  arbitrary cart-item-data keys, so session restore requires no extra work
  on the promotions side.

### Promotions side — every eligibility/discount path traced to one source

| # | Consumer | File:line | Reads |
|---|---|---|---|
| 1 | Context construction (single source of all cart rows) | `src/Woo/CartContextBuilder.php:78-129` | `WC()->cart->get_cart()` directly |
| 2 | `product_in_cart` | `src/Engine/Condition/ProductInCartCondition.php:53` | `context->get_items()` directly |
| 3 | `category_in_cart` | `src/Engine/Condition/CategoryInCartCondition.php:54` (via `CartItemSelector::items_matching_categories`) | `context->get_items()` |
| 4 | `product_quantity` | `src/Engine/Condition/ProductQuantityCondition.php:75` | `context->get_items()` (via `CartItemSelector::items_matching_products`) |
| 5 | `category_quantity` | `src/Engine/Condition/CategoryQuantityCondition.php:74` | `context->get_items()` directly |
| 6 | `minimum_cart_quantity` / `maximum_cart_quantity` | `MinimumCartQuantityCondition.php:33`, `MaximumCartQuantityCondition.php:33` | `CartQuantityHelper::total_quantity_from_items(context->get_items())` |
| 7 | `minimum_subtotal` / `minimum/maximum_eligible_subtotal` | `MinimumSubtotalCondition.php:32` | `context->get_cart_subtotal()`, itself set from `$items`-derived `eligible_subtotal` in `CartContextBuilder.php:157` |
| 8 | `exclude_sale_items` | `ExcludeSaleItemsCondition.php:23` | `context->get_items()` |
| 9 | `cheapest_item_discount` (fee preview) | `CheapestItemDiscountAction.php:155-216` | `EligibleCartScope::filter_items(context->get_items(), ...)` |
| 10 | Proportional line-discount allocation | `src/Engine/DiscountAllocationEngine.php:141-168` (`resolve_lines()`) | `context->to_array()['items']` |
| 11 | Line-price mutation (line/hybrid mode) | `src/Woo/LineItemDiscountApplier.php:112-159` (`apply_for_plan()`) | raw `$cart->get_cart()`, but only for `$cart_item_key`s the allocator (#10) already picked — never independently re-scans product/category |
| 12 | Fee-based discount amounts (default mode) | `src/Woo/CartPromotionApplier.php:818-976` | percentages/amounts computed off `context->get_cart_subtotal()` / `$subtotal` (#7), never per-line |
| 13 | Post-checkout redemption recording | `src/Woo/OrderPromotionRecorder.php:802` | `$order->get_items('fee')` only — **never** reads product line items, so nothing here can re-trigger eligibility off a persisted `_ucb_component` order-item meta |

Consumers 1–10 all bottom out in exactly one place: the `$items` array
assembled once in `CartContextBuilder::build_from_cart()`. Confirmed by
grep: `CartContextBuilder`/`build_from_cart` is referenced only from
`Admin/PromotionEditPage.php` (admin preview), `Plugin.php` (wiring),
`Woo/WooCommerceBridge.php` (hook registration), and `Woo/CartPromotionApplier.php`
itself — no second, independent cart-reading path exists for eligibility.
This holds for classic cart, Store API/Blocks, session-restored cart, and
post-recalculation cart alike, because all four read the same `WC()->cart`
singleton — there is no separate Store-API-specific context builder to
duplicate the fix into (unlike UCB itself, which does need a separate Store
API hook, but only for its own *display* concern, not promotions'
eligibility concern).

Line-number note: `src/Woo/LineItemDiscountApplier.php` and
`src/Woo/CartPromotionApplier.php` both received unrelated additions from
the concurrently-merged bulk-pricing v1 change set (a `CatalogBasePriceResolver`
import and related wiring). Line numbers above reflect current `main`; the
structure, order of guards, and this design's conclusions are unaffected —
bulk pricing does not touch `CartContextBuilder.php`, any file under
`src/Engine/`, or any condition class.

### Why the existing gift-card exclusion pattern is not reused as-is

`src/GiftCard/GiftCardPromotionExclusion.php` marks gift-card lines
(`is_gift_card_product`) in `CartContextBuilder`, then separately,
redundantly filters them at each subtotal/allocation site
(`EligibleCartScope::filter_items`, `DiscountAllocationEngine::resolve_lines`,
`LineItemDiscountApplier`'s per-line loop) — but **not** in
`ProductInCartCondition` or `CategoryInCartCondition`, which iterate raw
context items unfiltered. This is a live, provable gap in the
mark-and-filter-per-consumer pattern: a gift-card line can satisfy
`product_in_cart` today. Since the UCB requirement is unconditional,
copying that pattern verbatim would inherit this exact gap.

## Decision

**Placement:** exclude a UCB component-child cart row *before* it becomes
an `EvaluationContext` item — inside `CartContextBuilder::build_from_cart()`'s
existing loop over raw cart items (`src/Woo/CartContextBuilder.php:81-128`),
next to the existing free-gift marker line. Skip building/appending the row
for any cart item where `! empty( $cart_item['_ucb_component'] )`.

**Predicate:** `! empty( $cart_item['_ucb_component'] )` — a literal string
this repository owns, not a reference to UCB code. Presence-only, not
content-parsed (no snapshot-version check, no read of
`_ucb_parent_item_id`), so an unrecognized future UCB snapshot shape still
fails closed (still excluded).

**No `LineItemDiscountApplier` guard, and no other duplicated per-consumer
guard.** `DiscountAllocationEngine::resolve_lines()` (#10) is the only
source of the `cart_item_key`s `LineItemDiscountApplier` acts on, and it
already reads the same, now-filtered `context->to_array()['items']`. Once
the exclusion sits at #1, no component child's `cart_item_key` can ever
reach that map, so a second guard downstream would duplicate a check
against data that structurally cannot reach it.

**Proof the parent kit stays normally eligible:** the kit parent cart row
is never marked — `CartConstruction::maybeAddChildren()` only ever calls
`$cart->add_to_cart()` with `_ucb_component` for *children*. The parent
keeps its own real `product_id`, `quantity`, `categories`, and non-zero
`line_subtotal`/`unit_price` in `context->get_items()`, so every consumer
in the table above still evaluates it exactly as any ordinary product.

**Proof a standalone component purchase stays eligible:** a standalone
add-to-cart of the same product never carries `_ucb_component` — its
context row is built and kept exactly as today, unaffected by the new
guard.

**One-way, data-contract-only, no runtime dependency:** the guard reads one
array key already present on `$cart_item` (WooCommerce's own array
structure) using a promotions-owned literal string. No `use` of any
`UniversalCommerceBundles\...` class, no `class_exists()`/hook/constant
check, no activation-state check. Deactivating, uninstalling, or never
having installed UCB leaves the guard as harmless dead code that never
matches.

**Migration: none required.** `OrderPromotionRecorder` (#13) only reads
`$order->get_items('fee')` — fee lines this plugin itself created — never
product line items, so no persisted promotion/redemption/audit row can be,
or needs to be, retroactively distinguished by component-vs-real status.
Nothing in `Domain/Redemption.php`, `Domain/AuditLogEntry.php`, or
`Domain/PromotionSnapshot.php` stores a per-cart-line product reference
this change would invalidate.

## Frozen future implementation work package

Explicitly limited to:

1. ✅ The single guard in `CartContextBuilder::build_from_cart()` (above) —
   implemented.
2. ✅ A minimal `WC()`/`WC_Cart`/`WC_Customer` test stub added to
   `tests/bootstrap.php`, sufficient to exercise `build_from_cart()`'s real
   code path in `tests/Unit` — implemented. The stub's `WC()` singleton
   falls through unimplemented method calls to `null` via `__call()`
   rather than raising a fatal error, so introducing it does not change
   the outcome of pre-existing tests that use `function_exists('WC')` as a
   "WooCommerce inactive" signal.
3. ✅ The automated tests below — implemented in
   `tests/Unit/UcbComponentExclusionTest.php`.
4. ⬜ The mandatory live Store API/Blocks acceptance gate below — **not yet
   run.**

**Explicitly not in scope:** any change to `LineItemDiscountApplier`, any
new condition type or extension API, any change to free-gift/gift-card
behavior, any change to promotion persistence/redemption/schema/migrations,
any bulk-pricing change, UCB changes, fulfillment changes, host guard
deployment, catalogue/kit product setup, shipping/analytics work outside
this repository, a new "kits cannot be promoted" global policy, production
deployment, DEV deployment, tags, or releases.

## Automated tests (frozen scope)

New file `tests/Unit/UcbComponentExclusionTest.php`, two tiers.

**Why raw-array tests are not sufficient by themselves:** the existing
`tests/Unit/GiftCardPromotionExclusionTest.php` pattern (hand-built
`EvaluationContext` arrays fed straight to conditions/actions) proves
condition *logic* but never calls `CartContextBuilder::build_from_cart()` —
the method that actually reads `$cart_item['_ucb_component']` and does the
excluding. A suite built entirely that way could pass even if the real
guard were missing, wrong, or removed.

1. **Integration-level (required, exercises the real code path):** using
   the new cart stub, seed `WC()->cart`'s `get_cart()` return with a
   kit-parent row (no `_ucb_component`), a component-child row
   (`_ucb_component => 1`, zero `line_subtotal`), and a standalone-product
   row (same `product_id` as the child, no marker). Call
   `(new CartContextBuilder())->build_from_cart()` and assert on the real
   returned `EvaluationContext`:
   - the child's `item_key` is absent from `get_items()`;
   - the parent row and the standalone row are both present, unchanged;
   - feeding that real, builder-produced context into
     `ProductInCartCondition`, `CategoryInCartCondition`,
     `ProductQuantityCondition`/`CategoryQuantityCondition`,
     `MinimumCartQuantityCondition`, `CheapestItemDiscountAction::preview()`,
     and `DiscountAllocationEngine::allocate()` produces the correct
     pass/fail/amount results — not a second, hand-built context.
   - **Stub-level entry-path parity (necessary but not sufficient):** call
     `build_from_cart()` against two stub `WC_Cart` instances shaped after
     classic add-to-cart's and Store API/Blocks' respective on-cart rows and
     assert identical `EvaluationContext` output. This proves the builder
     is entry-path-agnostic *in principle* — it is not proof of real Store
     API/Blocks parity. That proof is the mandatory live acceptance gate
     below.
   - **Session-restore / recalculation parity:** call `build_from_cart()`
     again after mutating the stub cart the way `woocommerce_before_calculate_totals`
     / a session reload would, and assert the exclusion still holds.
2. **Unit-level (supplementary, fast, isolate pure logic):** mixed cart;
   UCB-absent-but-marker-present (no UCB classes loaded, only the raw array
   key); free-gift/gift-card exclusions unchanged; no persisted-data
   corruption — hand-built `EvaluationContext` arrays, per the existing
   `GiftCardPromotionExclusionTest.php` convention, since tier 1 already
   covers the builder itself.

## Mandatory live acceptance gate — **PASS**

Neither test tier above can exercise an actual Store API request or Blocks
session — a stub `WC_Cart` is not a REST controller, a WordPress request
lifecycle, or UCB's own Store-API-facing cart-construction hooks. This gate
closes that gap with a real, disposable environment. **Result: PASS.**

### Environment (disposable; fully torn down afterward)

- Isolated Docker Compose project (`internal: true` network, no published
  ports — no other service on this host was reachable from or could reach
  this stack, and vice versa).
- `mariadb:11.4`; `wordpress:6.9-php8.1-apache` + a `wordpress:cli-2.10-php8.1`
  companion sharing the same WordPress core volume; WordPress `6.9.0`.
- `woocommerce` plugin `11.0.1` (a real release build).
- `universal-commerce-bundles` — read-only copy of
  `feature/m1-fixed-kits-core` at commit `2bbb34e`. No source edit.
- `mp-commerce-promotions` — read-only copy of
  `feature/ucb-component-cart-exclusion` at commit `50ab1cb` (this PR).
- No DEV, staging, or production path touched at any point.

### Setup

- A kit product (id `12`, $100) marked `_ucb_is_kit` with one composition
  row referencing a component product (id `11`, $20, `qty_per_kit=1`).
- Two automatic promotions inserted directly via `PromotionRepository`:
  one `product_in_cart([11]) → fixed_amount_discount($5)` (targets the
  component — the one under test), one
  `product_in_cart([12]) → fixed_amount_discount($7)` (targets the kit
  parent — an independent control, proving the parent is not accidentally
  excluded too).

### Procedure and live results

| Step | Path | Cart contents | `fees` (Store API `GET /cart`) | `items_count` |
|---|---|---|---|---|
| 1 | Classic (`GET /?add-to-cart=12`) | Kit only | `[]` — **no fee** | 2 (kit + hidden child — proves UCB really added the child; it is merely hidden from the Store API item list, not absent from the real cart) |
| 2 | Classic, same session, `+ GET /?add-to-cart=11` | Kit + standalone component | `[{"...component-triggered-discount...", "total":"-500"}]` | 3 |
| 3 | Store API, **fresh session**, `POST /wc/store/v1/cart/add-item {id:12}` | Kit only | `[]` — **no fee** (byte-identical to step 1) | 2 |
| 4 | Store API, same session, `POST /wc/store/v1/cart/add-item {id:11}` | Kit + standalone component | `[{"...component-triggered-discount...", "total":"-500"}]` — **byte-identical to step 2** | 3 |
| 5 | Classic, third fresh session, kit only | Kit only | `[{"...kit-parent-triggered-discount...", "total":"-700"}]` | 2 |
| 6 | Store API, **fresh session**, `POST /wc/store/v1/cart/add-item {id:12}` | Kit only | `[{"...kit-parent-triggered-discount...", "total":"-700"}]` — **byte-identical to step 5** | 2 |

**What this proves, live, with real HTTP requests through both entry
points:**

1. Component children are excluded from promotion context/eligibility: a
   kit-only cart never satisfies the component-targeted promotion (steps
   1 and 3), even though the hidden child line genuinely exists in the
   real WooCommerce cart (`items_count` includes it).
2. The resulting discount is identical between paths: step 2 (classic) and
   step 4 (Store API) produce the exact same fee key, name, and amount
   (`-$5.00`) once a genuine standalone purchase of the component is
   added.
3. The kit parent remains eligible under ordinary promotion rules,
   independent of the component-exclusion logic, **in both paths**: step 5
   (classic) and step 6 (Store API, fresh session) both fire the
   kit-parent-targeted promotion identically (`-$7.00`) on a kit-only cart.
4. A standalone purchase of the same component product remains eligible in
   both paths: steps 2 and 4 are exactly this case, and the discount fires
   identically in each.

### Teardown

`docker compose down -v` (containers, volumes, network all removed);
scratch plugin-tree copies deleted; only base images that were already
shared with other unrelated work (`mariadb:11.4`) were left in the local
Docker image cache — the ephemeral `wordpress:6.9-php8.1-apache` and
`wordpress:cli-2.10-php8.1` images pulled for this run were removed.
Verified after teardown: no `ucb-promo-acceptance-*` container, volume, or
network remained.

## Non-goals

UCB changes; fulfillment changes; host guard deployment; catalogue/kit
product setup; shipping/analytics work outside this repository; a new
"kits cannot be promoted" global policy; an extension API or
self-registering UCB condition type; production deployment; DEV
deployment; tags; releases.

## Related documents

- [ADR-0001 — UCB component cart-line exclusion](adr/0001-ucb-component-cart-line-exclusion.md)
- `universal-commerce-bundles` repository: `docs/adr/0007-cross-cutting-cart-order-line-exclusion-contract.md`,
  `docs/adr/0004-fulfillment-plugin-expansion-and-compatibility-contract.md`
- `mp-commerce-fulfillment` repository: `docs/adr/0008-third-party-kit-parent-line-skip.md` (the analogous
  fulfillment-side decision this plan's placement choice mirrors)
