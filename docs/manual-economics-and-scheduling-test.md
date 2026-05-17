# Manual test: economics and scheduling

Schema **1.10.0** adds promotion budgets, code-batch export metadata, lifecycle filters, and schedule/economics reporting. Storefront behavior: exhausted budgets block application via restrictions (same as usage limits).

## Prerequisites

- Plugin active; `mp_cp_schema_version` = `1.10.0`.
- At least one promotion with recorded redemptions if testing budget spend progression.

## 1. Budget fields (edit screen)

1. Edit an **active** promotion.
2. In **Campaign metadata**, set **Budget amount** `100`, **Budget currency** `USD`.
3. Save and reload — **Budget progress** shows spent vs cap (0% when new).
4. Clear budget amount, save — cap removed (no progress row).

**Pass:** Validation rejects invalid amounts; currency optional but warned in Rule Validation when amount set without currency.

## 2. Lifecycle list filter

1. **WooCommerce → Promotions**.
2. Use **Lifecycle** dropdown: Ending soon, Budget exhausted, Scheduled, etc.
3. Status column shows workflow status plus lifecycle badge when different (e.g. `active (Ending soon)`).

**Pass:** Filter results match phase semantics; pagination preserves filter.

## 3. Schedule warnings

1. Create two **active** promotions with overlapping date windows and exclusive or free-shipping actions.
2. Edit one — **Schedule warnings** lists overlaps involving this promotion.
3. **Rule Validation** includes economics messages from `validate_with_catalog()`.

**Pass:** Read-only; no automatic status changes.

## 4. Code batch notes and export

1. On edit screen, **Generate code batch** — set **Batch notes**, optional prefix helper text.
2. After generation, download CSV once — succeeds.
3. Open **batch detail** — **Generated at**, **Exported at**, **Export count** = 1, notes visible.
4. Download again from outcome form if still shown — export count increments.

**Pass:** Audit log contains `promotion_code.batch_exported`; `record_export` updates batch row.

## 5. Batch status actions

On batch detail, when codes exist:

- **Disable entire batch (active → disabled)**
- **Expire entire batch (active → expired)**

**Pass:** Bulk code status changes; expired codes are not re-enabled by “enable disabled”.

## 6. Reports economics

1. **Reports** tab — choose **Date preset** (e.g. Last 7 days) and optional **Budget exhausted** filter.
2. Summary shows total budget spent, active budgeted count, exhausted count.
3. **Campaign economics** tables: Upcoming, Ending soon, Budget exhausted.
4. **Top promotions** includes **Budget utilization** when cap configured.
5. Export CSV — columns include `campaign_label`, `budget_amount`, `budget_spent`.

**Pass:** Preset overrides manual dates when selected.

## 7. Diagnostics maintenance

1. **Diagnostics** → **Deactivate exhausted promotions**.
2. Confirm active promotions with `budget_spent >= budget_amount` move to **paused** (audited).

**Pass:** POST + nonce; summary shows paused/skipped/errors counts.

## 8. WP-CLI smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/economics-scheduling-smoke.php
```

**Pass:** Schema 1.10.0, budget adjust, lifecycle filter, batch export, reports preset, schedule analyzer, pause exhausted.

## Limitations

- Budget tracking uses recorded redemption discount amounts (not a separate ledger table).
- Lifecycle `live` filter uses SQL heuristics aligned with `PromotionLifecycle::primary_phase()` ordering.
- No REST/AJAX endpoints for these admin flows.
