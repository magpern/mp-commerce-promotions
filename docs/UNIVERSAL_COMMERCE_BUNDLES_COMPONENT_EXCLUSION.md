# Universal Commerce Bundles component-line exclusion (v1.1 planning)

**Plugin:** MP Commerce Promotions `0.5.3` · **Status:** Frozen — pending Product Owner review of [ADR-0001](adr/0001-ucb-component-cart-line-exclusion.md)
**Scope:** planning + one narrow future implementation work package (this document does not implement anything)

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
| 11 | Line-price mutation (line/hybrid mode) | `src/Woo/LineItemDiscountApplier.php:100-217` | raw `$cart->get_cart()`, but only for `$cart_item_key`s the allocator (#10) already picked — never independently re-scans product/category |
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

### Why the existing gift-card exclusion pattern is not reused as-is

`src/GiftCard/GiftCardPromotionExclusion.php` marks gift-card lines
(`is_gift_card_product`) in `CartContextBuilder`, then separately,
redundantly filters them at each subtotal/allocation site
(`EligibleCartScope::filter_items:68`, `DiscountAllocationEngine::resolve_lines:158`,
`LineItemDiscountApplier:140`) — but **not** in `ProductInCartCondition` or
`CategoryInCartCondition`, which iterate raw context items unfiltered. This
is a live, provable gap in the mark-and-filter-per-consumer pattern: a
gift-card line can satisfy `product_in_cart` today. Since the UCB
requirement is unconditional, copying that pattern verbatim would inherit
this exact gap.

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

1. The single guard in `CartContextBuilder::build_from_cart()` (above).
2. A minimal `WC()`/`WC_Cart` test stub added to `tests/bootstrap.php` (or a
   dedicated stub file), sufficient to exercise `build_from_cart()`'s real
   code path in `tests/Unit` — this repository has no `WC()` function, no
   `WC_Cart`-shaped stub, and no separate WP/WooCommerce integration test
   suite today (`phpunit.xml.dist` covers `tests/Unit` only).
3. The automated tests below.
4. The mandatory live Store API/Blocks acceptance gate below.

**Explicitly not in scope:** any change to `LineItemDiscountApplier`, any
new condition type or extension API, any change to free-gift/gift-card
behavior, any change to promotion persistence/redemption/schema/migrations,
UCB changes, fulfillment changes, host guard deployment, catalogue/kit
product setup, shipping/analytics work outside this repository, a new
"kits cannot be promoted" global policy, production deployment, DEV
deployment, tags, or releases.

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

## Mandatory live acceptance gate (required before the implementation PR may be presented as merge-ready)

Neither test tier above can exercise an actual Store API request or Blocks
session — a stub `WC_Cart` is not a REST controller, a WordPress request
lifecycle, or UCB's own Store-API-facing cart-construction hooks. Before
this document's Store API/Blocks acceptance claim (task requirement:
"Classic-cart and Store API/Blocks flows produce the same result") can be
considered satisfied, a manual pass in a **disposable** WordPress/WooCommerce
environment is required — not automated CI, not this planning document, not
DEV, not production:

1. Install the implementation branch of `mp-commerce-promotions` and a
   built, released UCB artifact, read-only — no UCB source edits.
2. Add the same kit product to the cart twice, once per path: classic
   add-to-cart form submission; `POST /wc/store/v1/cart/add-item`.
3. With a matching automatic promotion active, verify for each path:
   - component children are excluded from promotion context/eligibility;
   - the resulting discount (fee amount or line allocation) is identical
     between paths;
   - the kit parent and a standalone purchase of the same component remain
     eligible.
4. Record the environment, versions, and pass/fail result in the
   implementation PR's closure evidence, at the same evidentiary standard
   this repository already uses for its own block-compatibility
   investigations (`docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md`,
   `docs/BLOCKS_QA_EVIDENCE_2026-05-18.md`). Tear down the disposable
   environment completely afterward.

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
