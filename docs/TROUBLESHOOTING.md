# Troubleshooting

## System health score low

Open **WooCommerce → Promotions → Diagnostics**:

1. **System health** — follow recovery recommendations.
2. **Ecosystem compatibility** — review detected plugins.
3. **Promotion health** — fix invalid dates, budgets, orchestration congestion.

## Promotions not applying

- Check **Safe mode** and **Promotion dry-run** (dry-run runs planner but skips fees).
- Verify promotion status, dates, and conditions.
- Check storefront **degraded mode** banner.
- Review **Concurrency warnings** for planner lock contention.

## Wrong discount on checkout

- Tax-inclusive stores: compare fee total to Reports allocation; open Diagnostics → **Tax compatibility** and Reports → tax-sensitive promotion count.
- Multi-currency: confirm amounts in base currency; Diagnostics shows currency confidence — use **fee_based** when confidence is low/unsupported.
- Native coupons: check `coupon_behavior` on the promotion; review **Coupon coexistence matrix** and coupon telemetry (`blocked_by_coupon_count`, `coexistence_fallback_count`, `coupon_conflict_count`).

## Coupon + promotion conflicts

1. Diagnostics → **Coupon coexistence matrix** — review scenario risks and warnings.
2. Promotion edit → **Coupon coexistence preview** before enabling line mode with coupons.
3. Run `scripts/coupon-compatibility-smoke.php` after plugin or WooCommerce coupon changes.

## Stale checkout certification

Diagnostics → **Checkout certification** flags areas not certified in 30+ days. Record a new run after browser QA (classic, blocks, line mode, or coupon coexistence).

## Emergency operations

Diagnostics → **Emergency operations** — always **Preview** first. Apply only with audit trail:

- Disable all automatic promotions (safe mode)
- Disable line-item mode globally
- Pause all stackable promotions
- Rebuild promotion caches / clear planner telemetry / reset degraded mode

## Order missing promotion meta

- Block checkout requires Store API hooks (0.2.0-beta.1+).
- Confirm order reached processing/completed for reversal tests.

## Slow cart

- Diagnostics → **High-complexity active promotions**.
- Profiler timing buckets under Performance & hardening.
- Reduce active promotion count.

## Stale locks after deploy

```bash
./wp eval 'echo wp_json_encode((new \MP\CommercePromotions\Service\PromotionConcurrencyGuard())->purge_stale_locks(false));'
```

Or use Diagnostics recovery tools (dry-run first).

## Smoke checks

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/coupon-compatibility-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stress-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-compatibility-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stabilization-smoke.php
```

Incident playbooks: [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md). Operations: [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md).
