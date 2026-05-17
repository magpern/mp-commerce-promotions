# Manual test: promotion templates

Promotion templates generate **existing** condition/action JSON only. They do **not** add new engine rule types or schema changes.

## Where to find it

**WooCommerce → Promotions → Edit promotion → Rules → Promotion templates** (above Simple Rule Builder).

## Templates

| Template | Creates |
|----------|---------|
| Percent off category | Optional `minimum_eligible_subtotal` + scoped `percentage_discount` |
| Fixed amount off products | Optional `minimum_eligible_subtotal` + scoped `fixed_amount_discount` |
| Buy X get Y cheapest free | `cheapest_item_discount` (category or products scope) |
| Free shipping over subtotal | `minimum_subtotal` + `free_shipping` |
| Free gift over subtotal | `minimum_subtotal` + `free_gift_product` |
| First order discount | `first_order` + percentage or fixed action |
| Customer role discount | `customer_role` + percentage or fixed action |

## Apply flow

1. Select a template and fill the fields noted in each template description.
2. Click **Apply template to rules**.
3. Confirm success notice; raw JSON below should reflect the preset.
4. Use **Preview cart** (if available) to verify eligibility on a test cart.

## Overwrite warning

Applying a template **replaces** conditions, actions, and restrictions. It does **not** change:

- Name, status, dates
- Usage limits
- Application rules (exclusive/stackable, exclusions)
- Promotion codes or batches

## Advanced editing

After applying a template, edit **Raw JSON editor** for fine-tuning. Use **Simple Rule Builder** for single condition/action pairs.

## WP-CLI smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/promotion-template-smoke.php
```

## Known limitations

- No visual/JS wizard; all template fields are visible on one form.
- No marketplace template library or import/export.
- Templates do not set promotion-level product/category exclusions (configure separately).
