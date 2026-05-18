# Tax compatibility

Fee-based discounts use cart subtotal as WooCommerce exposes it. Line-item mode mutates unit prices and needs extra care when `wc_prices_include_tax()` is true.

## Diagnostics

**Diagnostics → Tax compatibility** shows:

- Prices include tax (yes/no)
- Shop/cart tax display modes
- Rounding risk (low / medium / high)
- Warnings for line mode, shipping, scoped fixed amounts

## Reports

Summary card **Tax-sensitive promotions** counts active/draft/paused promotions using line/hybrid modes or shipping/fixed actions.

## Smoke

Tax scenarios are included in `TaxCompatibilityAnalyzer::simulate_tax_inclusive_scenarios()` (admin/read-only).

## Recommendation

Prefer **fee_based** on tax-inclusive catalogs unless you have completed checkout certification for line mode.
