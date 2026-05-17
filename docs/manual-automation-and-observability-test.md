# Manual test: automation and observability (schema 1.12.0)

## Prerequisites

- Plugin active; schema **1.12.0** (`./wp option get mp_cp_schema_version`).
- WooCommerce admin access (`manage_woocommerce`).

## Automation runs

1. Open **WooCommerce → Promotions → Diagnostics**.
2. Click **Run all automation** (confirm). Expect success notice.
3. Scroll to **Automation run history** — latest row with type `run_all`, status Completed, JSON summary via **View summary**.
4. Open **Reports** — **Automation history** table shows recent runs.

**WP-Cron (optional):** Enable **Settings → Enable WP-Cron automation** to schedule hourly maintenance (`mp_cp_cron_hourly_maintenance`) and daily cleanup (`mp_cp_cron_daily_cleanup`). Default is **off**. Hourly runs respect **Automation emergency stop** and **manual only**. Cron runs audit `promotion.automation_cron_run`. Diagnostics shows hook schedule state.

## Promotion health

1. On Diagnostics, review **Promotion health** (severity, code, promotion IDs, message).
2. On **All Promotions**, use quick filter **Health issues** (if issues exist).
3. On **Reports**, confirm **Promotion health summary** counts.

## Planner telemetry

1. Add a product to cart on storefront (logged-in if testing cooldown/segmentation).
2. Open cart/checkout so totals recalculate.
3. On **Reports → Planner telemetry**, confirm **Most selected** / **Most blocked** rows update (aggregate counters only; no customer PII).

## Rollback and recovery (Diagnostics)

For each tool, run once without **Apply changes** (dry-run), then with apply if safe:

1. **Recalculate budget_spent from redemptions**
2. **Rebuild planner telemetry from redemption history**
3. **Validate promotion snapshots**
4. **Repair invalid orchestration groups**

All require POST, nonce, and capability checks.

## Snapshots

1. Edit a promotion; apply a template or duplicate — **Recent snapshots** shows label/source when captured.
2. Use **Filter by type** dropdown; note snapshot count.
3. **Restore** shows extended confirmation warning.

## Duplicate presets

On edit screen **Status actions → Duplicate promotion**:

- Scheduled draft
- Without budget
- Optional orchestration group override

## Smoke script

From WooCommerce project root:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/automation-observability-smoke.php
```

## Limitations

- No external analytics or SaaS sync.
- No REST/AJAX automation triggers.
- Telemetry is aggregate per promotion only (no cart/customer identifiers stored).
