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

- Tax-inclusive stores: compare fee total to Reports allocation.
- Multi-currency: confirm amounts in base currency.
- Native coupons: check `coupon_behavior` on the promotion.

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
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/ga-stabilization-smoke.php
```
