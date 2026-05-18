# Merchant safety

## Promotion dry-run

**Global dry-run:** Settings → Production safety → **Promotion dry-run (global)** (`mp_cp_promotion_dry_run`). Evaluates promotions but does not apply fees, gifts, or line mutations.

**Per-promotion dry-run:** Edit promotion → **Dry run** checkbox (`dry_run=1` in DB). Appears in planner trace; session entries include `dry_run_mode`; redemptions are not recorded. Global dry-run overrides all promotions.

When enabled:

- Planner and session logic still run.
- **Cart fees are not applied** (`CartPromotionApplier::add_promotion_fee` returns early).
- Use on staging to preview which promotions would apply without customer-facing discounts.

## Heuristic warnings

`MerchantSafetyAdvisor` flags (Diagnostics → Merchant safety):

- High percentage discounts (≥30% warning, ≥50% critical)
- Large fixed discounts without budget caps
- Stackable promotions without `stop_processing`
- Budget ≥90% consumed
- Active promotions without end dates

## Exposure estimate

`MerchantSafetyAdvisor::estimate_max_cart_exposure()` provides a per-promotion heuristic for a reference subtotal (admin/reporting use).

## Safe mode

`mp_cp_safe_mode` disables automatic promotions while allowing codes when configured. Prefer dry-run for staging previews that still exercise the planner.
