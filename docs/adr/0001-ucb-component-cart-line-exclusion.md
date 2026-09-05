# ADR-0001 — Exclude Universal Commerce Bundles component-child cart lines from promotion eligibility

## Status

Accepted — Product Owner approved, ordinary roadmap process. This
repository has no active Pilot Stabilization Policy or feature freeze; this
is `v1.1` roadmap work, not an exception to a frozen-pilot line. This is
also this repository's first ADR (`docs/adr/` did not previously exist
here).

**Implementation status:** the production guard (Decision §1), the required
PHPUnit builder-level tests, and the mandatory live Store API/Blocks
acceptance gate (Decision §8) are all complete on
`feature/ucb-component-cart-exclusion` — see that branch's pull request for
the live-acceptance evidence. See
`docs/UNIVERSAL_COMMERCE_BUNDLES_COMPONENT_EXCLUSION.md` for the frozen
plan, full evidence table, required tests, and the live-acceptance
procedure and results.

## Context

Universal Commerce Bundles ("UCB") Architecture B represents one kit
purchase as one priced **kit-parent** cart/order line plus real,
zero-priced **component-child** cart/order lines — one per component, each
a real WooCommerce line WooCommerce's own core stocks and reduces normally.

`mp-commerce-promotions`'s condition/discount engine currently treats every
cart line as a genuine customer selection. UCB's own
`docs/adr/0007-cross-cutting-cart-order-line-exclusion-contract.md` names
this exact leak against "a companion promotions plugin's condition engine —
product/category/quantity conditions satisfied by a hidden child alone,
with no genuine standalone purchase of that component in the cart" and
documents the marker it exposes (`_ucb_component`) for a companion
promotions plugin to consume. The consuming side had never been designed.

Source-led forensics (full evidence table in the plan doc) traced every
condition (`product_in_cart`, `category_in_cart`, `product_quantity`,
`category_quantity`, `minimum`/`maximum_cart_quantity`,
`minimum_subtotal`/`minimum`/`maximum_eligible_subtotal`,
`exclude_sale_items`), the `cheapest_item_discount` action's preview, and
the proportional line-discount allocator
(`DiscountAllocationEngine::resolve_lines()`) to exactly one shared source:
the `$items` array `CartContextBuilder::build_from_cart()` assembles once
per evaluation from `WC()->cart->get_cart()`
(`src/Woo/CartContextBuilder.php:78-129`). No second, independent
cart-reading path exists for eligibility across classic cart, Store
API/Blocks, session-restored cart, or post-recalculation cart — all four
read the same `WC()->cart` singleton.

The existing analogous pattern in this codebase —
`GiftCardPromotionExclusion` marking a line (`is_gift_card_product`) in
`CartContextBuilder`, then separately re-filtering it at each
subtotal/allocation site — was evaluated and found to have a live, provable
gap: `ProductInCartCondition` and `CategoryInCartCondition` iterate raw
context items unfiltered and never check the gift-card marker, so a
gift-card line can satisfy `product_in_cart` today. Since the UCB
requirement is unconditional ("must not qualify a promotion by product,
category, quantity, or equivalent cart-condition logic" — no exception),
reusing that mark-and-filter-per-consumer pattern verbatim would inherit
this exact gap.

## Decision

1. In `CartContextBuilder::build_from_cart()`, inside the existing loop
   over raw cart items, immediately alongside the existing free-gift marker
   handling, skip building/appending the evaluation-context row for a cart
   item carrying the UCB component marker:

   ```php
   if ( ! empty( $cart_item['_ucb_component'] ) ) {
       continue;
   }
   ```

2. **Cart-level literal string only.** `'_ucb_component'` is used as a
   literal array key on the raw WooCommerce cart-item array (the shape
   `WC_Cart::add_to_cart()`'s `$cart_item_data` parameter produces) — not
   `WC_Order_Item::get_meta()`, which is a different, post-checkout call
   shape on a different object. No class, constant, hook, autoloader, or
   activation check from Universal Commerce Bundles is referenced, matching
   the same rule `mp-commerce-fulfillment`'s ADR-0008 established for its
   own `_ucb_kit` guard.
3. **Presence, not content, is the predicate.** The guard does not parse
   `_ucb_parent_item_id`, a snapshot version, or any other field, so an
   unrecognized future UCB snapshot shape still fails closed (the child is
   still excluded).
4. **Single choke point — no per-consumer duplication.** Every condition
   and the discount allocator read `context->get_items()` (or a value
   derived from it), so this one guard is airtight for all of them.
   `LineItemDiscountApplier`'s line-mutation loop reads only the
   `cart_item_key`s `DiscountAllocationEngine::resolve_lines()` already
   selected from the same, now-filtered items — no component child's key
   can reach it. **No second guard is added there or anywhere else.**
5. **No schema, persistence, or migration change.**
   `OrderPromotionRecorder` only reads `$order->get_items('fee')` (fee lines
   this plugin itself created) for redemption recording — never product
   line items — so no persisted promotion/redemption/audit row can be, or
   needs to be, retroactively distinguished by component-vs-real status.
6. **Test-harness addition is part of this decision's implementation
   scope.** This repository has no `WC()` function or `WC_Cart`-shaped
   stub in `tests/bootstrap.php` today, so `CartContextBuilder::build_from_cart()`
   cannot currently be exercised by any existing test. A minimal cart stub
   is added so at least one test calls the real method, not a hand-built
   `EvaluationContext`.
7. **Rollout has no ordering gate.** Unlike `mp-commerce-fulfillment`'s
   ADR-0008 (which required "merge → release → deploy → only then make a
   kit product purchasable" because fulfillment's intake is write-once with
   no re-sync path), this guard is pure read-time filtering of a live cart
   evaluated fresh on every request. It may be deployed in any order
   relative to UCB with no unrepairable state.
8. **Store API/Blocks parity is proven live, not only by unit test.** A
   PHPUnit `WC_Cart` stub can prove the builder treats two hand-shaped cart
   states identically, but it cannot exercise an actual Store API request,
   Blocks session handling, or UCB's own Store-API-facing cart-construction
   hooks. The implementation PR must additionally pass a manual acceptance
   check in a disposable WordPress/WooCommerce environment — a built,
   read-only UCB artifact, a kit added via both classic add-to-cart and
   `POST /wc/store/v1/cart/add-item`, and identical promotion
   context/eligibility/discount results between the two paths — before
   being presented as merge-ready. See the plan document's "Mandatory live
   acceptance gate" for the full procedure.

## Rejected alternatives

- **Mark-then-filter-per-consumer** (UCB's own ADR-0007 non-binding
  suggestion: "a new `is_kit_component` field... checked at each
  condition's matching point"). Rejected with this codebase's own
  gift-card exclusion as live, provable evidence of the gap this shape
  produces: `ProductInCartCondition`/`CategoryInCartCondition` never apply
  the gift-card filter today, so a marker that must be checked "at each
  matching point" is only as complete as the least-updated matching point.
- **A new promotion condition type for kit-awareness.** Rejected per UCB's
  own ADR-0007 rejected-alternatives reasoning: an unrecognized condition
  type makes the whole promotion ineligible in this engine's evaluator — a
  materially worse failure mode than a narrow per-row exclusion.
- **An extension API or self-registering UCB condition type.** Rejected —
  the promotions plugin owns native recognition of the stable,
  UCB-published cart-data contract; UCB never registers, calls, or extends
  anything inside promotions, and promotions never loads UCB. Deactivating
  UCB must never make unrelated promotions ineligible, which a
  registration-based design would risk.
- **A second, defensive `_ucb_component` guard inside
  `LineItemDiscountApplier`'s line-mutation loop.** Rejected, not merely
  deferred: `DiscountAllocationEngine::resolve_lines()` is the only source
  of the `cart_item_key`s that loop acts on, and it already reads the same,
  now-filtered `context->to_array()['items']`, so a second guard would
  duplicate a check against data that structurally cannot reach it — the
  "duplicated per caller" shape `mp-commerce-fulfillment`'s own ADR-0008
  rejected in favor of fixing the one shared reader.
- **A global "kits cannot be promoted" policy.** Not requested and
  explicitly out of scope — the kit parent is a real, priced line and
  follows ordinary promotion rules like any other product.

## Consequences

- Component-child lines never satisfy `product_in_cart`, `category_in_cart`,
  any quantity condition, `exclude_sale_items`, or contribute to eligible
  subtotal, cheapest-item selection, or discount allocation — for every
  current and future consumer of `context->get_items()`, with one change.
- The kit parent remains fully eligible under ordinary promotion rules — it
  is never marked and its context row is unchanged.
- A standalone purchase of the same component product is unaffected — its
  cart item carries no `_ucb_component` key and is built and kept exactly
  as before this change.
- Deactivating, uninstalling, or never having installed UCB leaves the
  guard as harmless dead code that never matches — no coupling exists in
  either direction (confirmed by evidence that `OrderPromotionRecorder`
  never reads product line items at all, so UCB's persisted order-item meta
  is never read back into a promotion decision).
- No schema migration, no change to free-gift or gift-card handling, no new
  condition type, and no extension surface for UCB.
- The Store API/Blocks parity claim is not closed by unit tests alone — the
  manual disposable-environment release-acceptance pass (Decision §8) is a
  named precondition before the implementation PR may be presented as
  merge-ready.

## Related

`docs/UNIVERSAL_COMMERCE_BUNDLES_COMPONENT_EXCLUSION.md` (frozen plan, full
evidence table, acceptance tests, and the mandatory live acceptance gate);
`universal-commerce-bundles` repository's
`docs/adr/0007-cross-cutting-cart-order-line-exclusion-contract.md` and
`docs/adr/0004-fulfillment-plugin-expansion-and-compatibility-contract.md`;
`mp-commerce-fulfillment` repository's
`docs/adr/0008-third-party-kit-parent-line-skip.md` (the analogous
fulfillment-side decision this ADR's placement choice mirrors) and
`docs/PILOT_STABILIZATION_POLICY.md` (not applicable to this repository —
cited only for contrast, since that repository's ADR-0008 operates under a
feature-freeze exception this repository does not have).
