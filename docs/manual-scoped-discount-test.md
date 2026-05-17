# Manual test: scoped discount calculations

Scoped discounts compute **eligible subtotal** from matching cart lines only. Storefront application remains **negative cart fees** — line prices are not mutated.

## Preconditions

- Plugin active; cart with multiple products in different categories.
- At least one product on sale (optional, for sale-exclusion tests).

## 1. Minimum eligible subtotal

Condition JSON:

```json
[{"type":"minimum_eligible_subtotal","amount":100,"category_ids":[10]}]
```

- Passes when sum of `line_subtotal` for lines in category 10 ≥ 100.
- Fails with `eligible_subtotal_too_low` when below threshold.
- Empty `product_ids` / `category_ids` → all cart lines count (after promotion-level exclusions on evaluation context).

## 2. Maximum eligible subtotal

```json
[{"type":"maximum_eligible_subtotal","amount":500}]
```

- Fails with `eligible_subtotal_too_high` when scoped subtotal exceeds amount.

## 3. Scoped percentage discount

```json
[{"type":"percentage_discount","percentage":20,"category_ids":[10]}]
```

- Preview shows `eligible_subtotal` and `calculated_discount`.
- Cart fee uses `calculated_discount` (still capped globally against paid cart subtotal).

With sale exclusion:

```json
[{"type":"percentage_discount","percentage":15,"product_ids":[100],"exclude_sale_items":true}]
```

## 4. Scoped fixed amount

```json
[{"type":"fixed_amount_discount","amount":25,"product_ids":[100,101]}]
```

- `applied_discount` = min(configured amount, eligible subtotal).
- `not_applicable` when eligible subtotal ≤ 0 (no matching lines).

## 5. Cheapest item (EligibleCartScope)

Existing `cheapest_item_discount` uses the same scope helper internally; traces may include `eligible_subtotal` and `matched_items_count`.

## 6. WP-CLI smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/scoped-discount-smoke.php
```

## Known limitations

- Fees do not appear as per-line discounts in WooCommerce totals breakdown.
- Scoped subtotal uses `line_subtotal` from cart context when present.
- No coupon stacking interoperability.
- Promotion-level excluded product/category IDs still apply before scope filtering (evaluation context).
