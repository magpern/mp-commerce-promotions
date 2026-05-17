# Manual test: orchestration and segmentation

Schema **1.11.0** adds `cooldown_hours`, `orchestration_group`, customer segmentation conditions, promotion snapshots, and scheduler automation helpers.

## Prerequisites

- Plugin active; `mp_cp_schema_version` = `1.11.0`.
- Logged-in customer with order history for segmentation tests (Woo enriches `lifetime_spend`, `order_count`, `average_order_value` in cart context).

## 1. Orchestration group (edit screen)

1. Edit an **active** promotion.
2. In **Campaign metadata**, set **Orchestration group** to `welcome-series` and save.
3. Create a second active promotion with the same group and overlapping dates.
4. **Cart preview** on either promotion — plan explanation should show one selected and the other `orchestration_group_blocked`.
5. **Conflict analysis** should list `orchestration_congestion` when schedules overlap.

**Pass:** Only one promotion per group is selected in the plan; congestion warning is read-only.

## 2. Cooldown hours

1. Set **Cooldown hours** `24` on a promotion; ensure a logged-in customer has a recent recorded redemption for that promotion.
2. Preview cart — promotion should be ineligible or skipped with cooldown reason.
3. Clear cooldown or wait / use another customer — promotion becomes eligible again.

**Pass:** Guests fail customer-required when cooldown is set; cooldown trace uses `promotion_cooldown_active`.

## 3. Segmentation conditions

1. Use **Simple Rule Builder** — condition `Customer lifetime spend` with operator `>=` and amount; pair with a discount action. Apply builder.
2. Add **logged_in** in raw JSON if not already present (validator warns without it).
3. Preview with a logged-in customer whose metadata meets the threshold.

**Pass:** VIP / loyal / returning **templates** produce logged_in + segmentation condition + discount.

## 4. Recent snapshots

1. Apply a **promotion template** or **rule builder** — a snapshot row is created automatically.
2. **Recent snapshots** section lists latest entries with **Restore** (POST + nonce).
3. Restore — promotion fields revert to snapshot JSON; success notice shown.

**Pass:** Restore overwrites promotion row; audited as `promotion.snapshot_restored`.

## 5. Reports orchestration

1. **Reports** tab — summary shows cooldown-active count and avg discount per redemption.
2. **Orchestration** section — top groups and highest budget burn tables.
3. Export CSV — columns `orchestration_group`, `cooldown_hours`, `budget_utilization_percent`.

## 6. Diagnostics scheduler automation

1. **Diagnostics** → run **Activate scheduled promotions**, **Archive expired paused**, **Normalize promotion states** (each POST + confirm).
2. Verify audited status changes match expectations.

## 7. WP-CLI smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/orchestration-segmentation-smoke.php
```

**Pass:** Schema 1.11.0, persistence, planner explain metrics, templates, snapshots, reports CSV, automation batch shapes.

## Cross-links

- [manual-conflict-analysis-test.md](manual-conflict-analysis-test.md)
- [manual-promotion-templates-test.md](manual-promotion-templates-test.md)
- [manual-economics-and-scheduling-test.md](manual-economics-and-scheduling-test.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
