# GA readiness delta

**Plugin:** 0.3.0-pilot.2 · **Schema:** 1.17.0

## GA-ready (ecosystem certification closure)

| Area | Status |
|------|--------|
| Global / per-promotion dry-run | Settings UI + edit checkbox + storefront guard |
| Schedule conflict preview | Edit screen + Diagnostics summary |
| Coupon coexistence matrix | Diagnostics + edit preview + `scripts/coupon-compatibility-smoke.php` |
| Tax-inclusive diagnostics | `TaxCompatibilityAnalyzer` + Reports tax-sensitive count + [TAX_COMPATIBILITY.md](TAX_COMPATIBILITY.md) |
| Multi-currency degradation | Detection + confidence (high/medium/low/unsupported) + fee_based recommendation |
| Checkout certification tracking | `mp_cp_certification_runs` table; stale >30d warnings |
| Emergency operations | Diagnostics POST + nonce + audit log + dry-run preview |
| Load harness groundwork | `scripts/load-harness.php` (synthetic planner loops, no orders) |
| Operations docs | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md), [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md), [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md) |
| Stress smoke | `scripts/ga-stress-smoke.php` |

## Still pilot / pre-GA (0.3.x)

- **0.3.0-pilot.2** — supersedes pilot.1; Campaign Builder default-route asset fix
- **0.3.0-pilot.1** — superseded (default-route regression)
- Line-item / hybrid discount application (experimental; global disable available)
- Cart/Checkout Blocks certified for fee path; line mode certification is manual/recorded
- Ecosystem matrix is detection + operational tooling — not live proof on every merchant stack
- PHPCS full tree not gating (advisory in CI)

## Uncertified (explicit)

- Woo Subscriptions / Bundles / Composite / Germanized at production scale
- Multi-currency + tax-inclusive + line mode combined stacks (unsupported → fee_based)
- Sustained 10k+ order load (load harness is synthetic only)

## Required before 1.0.0

1. Browser certification records for all four areas (classic, blocks, line mode, coupon coexistence) within 30d
2. PHPCS gating policy + error budget on `src/Service`, `src/Woo`, Admin reports/diagnostics
3. Blocks + line discount certification or line mode disabled by default for GA
4. Load harness baselines documented per store profile (promotion count, HPOS, object cache)
5. Merchant-facing dry-run and coupon coexistence documentation in onboarding
