# Merchant workflows (production pilot)

Operational playbooks for real merchant pilots using Commerce Promotions for WooCommerce (**Commerce Growth** admin) — schema **1.19.0**.

**Admin entrypoint:** WooCommerce → **Commerce Growth** → **Campaign Builder** (default). Use **Advanced Promotions** for expert list/edit and **Advanced editor** per promotion.

## Seasonal campaigns

1. Duplicate last year’s promotion or apply a template.
2. Review **schedule conflict preview** on each promotion before activation.
3. Use **orchestration groups** so only one seasonal winner applies per cart.
4. Run `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/regression-suite.php` after bulk edits.

## Flash sales

1. Set short `starts_at` / `ends_at` windows and a **budget cap**.
2. Prefer **exclusive** application mode for the hero offer; stack only accessory perks (free shipping).
3. Monitor Diagnostics → **Runtime anomalies** and **budget exhaustion** signals during the sale.
4. Keep **fee_based** discounts unless line mode is certified for your checkout.

## VIP campaigns

1. Target with customer segment / role conditions.
2. Use promotion codes with usage limits; avoid sharing stackable VIP + public coupons without review.
3. Enable **coupon coexistence preview** on the promotion edit screen.

## Dry-run rollout

1. Enable **global promotion dry-run** in Settings (planner runs, no fees).
2. Or set per-promotion **dry_run** for a single campaign.
3. Validate cart preview and Reports before disabling dry-run.
4. Roll back via Diagnostics → **Rollback dry-run activations** if needed.

## Staged activation

1. Create promotions as **draft**; capture snapshots via template/builder apply.
2. Activate in waves; pause lower-priority stackable promotions first.
3. Record checkout certification runs after each wave (Diagnostics).

## Rollback

1. **Promotion edit** → restore a specific snapshot, or review **latest snapshot diff**.
2. **Diagnostics** → rollback by snapshot ID, or rollback all promotions modified in the last N hours (audit + snapshot).
3. Emergency rollback reverses safe mode, global line-mode disable, and recent emergency pauses.

## Budgeted campaigns

1. Set `budget_amount` and monitor `budget_spent` in Reports.
2. Use automation to pause exhausted promotions (manual or cron when enabled).
3. Watch anomaly indicator **budget_exhaustion_spike** under load.

## Stackable orchestration strategy

- Use **stackable** for additive perks (shipping + small cart %).
- Use **exclusive** for primary discounts.
- Assign **orchestration_group** when multiple exclusives compete; only one winner per group.
- Run load harness with `MP_CP_LOAD_POOL=orchestration` before high-traffic events.

## Line mode rollout strategy

1. Start on **conservative** production profile (line mode disabled globally).
2. Pilot **fee_based** on classic checkout; certify blocks separately.
3. Switch to **balanced** profile when line mode is required; monitor line fallback telemetry.
4. Use **aggressive** only with full certification and `scripts/load-harness.php` with `MP_CP_LOAD_POOL=line`.

## Selling gift cards (products)

1. Create a normal **simple** or **variable** WooCommerce product.
2. Enable **This product sells a gift card** on the product (or variation).
3. Choose **Same as product price** or **Fixed amount**; optional expiry days.
4. Customer checks out as usual; codes generate when the order reaches **Processing** or **Completed**.
5. Codes email to the **billing address** when delivery email is enabled in Settings.
6. On cancellation/refund, unused generated cards are voided automatically; partially used cards need manual review (order note).

Recipient mode is **purchaser only** (no separate recipient form yet). See [GIFT_CARD_PRODUCTS.md](GIFT_CARD_PRODUCTS.md).

## Related scripts

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/regression-suite.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/load-harness.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stress-smoke.php
```

See also [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) and [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md).
