# Manual test: product targeting and variation awareness

Schema **1.8.0** adds promotion-level product/category exclusions and variation-aware cart matching.

## Preconditions

- WooCommerce store with at least one variable product (parent + variations) and one product on sale.
- Plugin active; `./wp plugin list` shows `mp-commerce-promotions` active.

## 1. Variation-aware `product_in_cart`

1. Add a variable product variation to the cart (note parent ID and variation ID).
2. Create a promotion with condition JSON:

   ```json
   [{"type":"product_in_cart","product_ids":[VARIATION_ID]}]
   ```

3. Cart preview / evaluator should pass when the variation is in cart.
4. Remove the line; preview should fail with reason `required_product_missing`.

## 2. `category_in_cart`

1. Note a product category ID assigned to a cart line.
2. Condition:

   ```json
   [{"type":"category_in_cart","category_ids":[CATEGORY_ID]}]
   ```

3. Passes when any scoped line has that category; fails with `required_category_missing` otherwise.

## 3. `exclude_sale_items` condition

1. Add a product currently on sale (Woo “Sale price”).
2. Condition `[{"type":"exclude_sale_items"}]` should fail with `sale_items_present`.
3. Remove sale lines or end the sale; condition should pass.

## 4. Cheapest item: variations and sale exclusion

Action example:

```json
[{
  "type":"cheapest_item_discount",
  "scope":"products",
  "product_ids":[PARENT_ID],
  "variation_ids":[VAR_ID_1,VAR_ID_2],
  "required_quantity":3,
  "discounted_quantity":1,
  "discount_percentage":100,
  "exclude_sale_items":true
}]
```

- Only matching variations count toward the pool.
- Sale lines are omitted from the eligible unit pool when `exclude_sale_items` is true.
- Trace/preview may show `eligible_units_raw` vs `eligible_units` when sale lines were filtered.

## 5. Promotion-level exclusions

On promotion edit, **Product targeting exclusions**:

- Excluded product IDs (simple, parent, or variation IDs).
- Excluded category IDs.

Excluded lines are ignored for evaluation scope (conditions, cheapest item pool, cart quantity helpers) but remain in the WooCommerce cart.

## 6. WP-CLI smoke

From the WooCommerce project root:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/product-targeting-smoke.php
```

Expect `Success: product-targeting-smoke completed.`

## Known limitations

- Sale detection uses cart line `on_sale` from `CartContextBuilder` (`wc_get_product()->is_on_sale()`). Custom pricing plugins may not set this flag.
- Exclusions affect promotion evaluation only, not WooCommerce cart contents or coupons.
- `product_in_cart` `product_ids` matches both parent product IDs and variation IDs in the same list.
- No REST/AJAX builder; use admin checkboxes and comma-separated ID fields.
