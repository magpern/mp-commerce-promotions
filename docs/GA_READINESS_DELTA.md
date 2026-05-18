# GA readiness delta

**Plugin:** 0.2.0-beta.1 · **Schema:** 1.16.0

## GA-ready (this closure)

| Area | Status |
|------|--------|
| Global promotion dry-run | Settings UI + POST save + support bundle + storefront guard |
| Per-promotion dry-run | `dry_run` column; edit checkbox; list badge; no redemption; session `dry_run_mode` |
| Schedule conflict preview | Edit screen + Diagnostics summary (schedule + conflict + budget overlap) |
| Stress smoke | `scripts/ga-stress-smoke.php` (100 promos, planner, archive cleanup) |
| Docker verification | Documented in `docs/DEVELOPMENT.md` |
| Schema | 1.16.0 (`dry_run` on promotions) |

## Still beta (0.2.x)

- Line-item / hybrid discount application (experimental)
- Cart/Checkout Blocks certified for fee path; line mode not blocks-certified
- Ecosystem matrix is detection-only (no live proof with every extension stack)
- PHPCS full tree not gating

## Uncertified (explicit)

- Woo Subscriptions / Bundles / Composite / Germanized at production scale
- Multi-currency + tax-inclusive combined stacks
- 10k+ order stores under sustained load (stress smoke is synthetic only)

## Required before 1.0.0

1. Extension certification matrix with real merchant stacks (not detection-only)
2. PHPCS gating policy + error budget on `src/Service`, `src/Woo`, Admin reports/diagnostics
3. Blocks + line discount certification or line mode disabled by default for GA
4. Load test harness (orders + promotions) beyond `ga-stress-smoke.php`
5. Merchant-facing dry-run documentation in onboarding
