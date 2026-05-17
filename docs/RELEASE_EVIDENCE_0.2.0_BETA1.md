# Release evidence — 0.2.0-beta.1 (candidate)

**Document date:** 2026-05-17  
**Plugin version in tree:** 0.1.0 (not yet bumped)  
**Target tag:** `v0.2.0-beta.1`  
**Schema:** 1.14.0  

---

## Source control

| Item | Value |
|------|--------|
| **Latest commit** | `808a261` (browser QA prep); classic QA + recording fix on `main` after this doc |
| **Repository** | https://github.com/magpern/mp-commerce-promotions |
| **Branch** | `main` |

---

## Automated verification

| Check | Result (2026-05-17) |
|-------|---------------------|
| `composer validate --strict` | Pass |
| `composer run lint:php` | Pass |
| `composer run test` | **324 tests, 683 assertions**, 1 skipped |
| `composer run lint:phpcs` | Non-zero; CI **continue-on-error** |
| `scripts/build-zip.sh` | Pass → `mp-commerce-promotions-0.1.0.zip` |
| `scripts/release-audit.sh` | Pass |
| `scripts/verify-plugin.sh` | Partial — activate/deactivate + schema OK; zip audit fails when run from live plugin path (use staging `release-audit.sh`) |
| `scripts/beta-readiness-smoke.php` | 15/15 |
| `scripts/beta-release-prep-smoke.php` | **16/16** |

### PHPCS snapshot (target paths, post-PHPCBF on staging)

| Scope | Errors | Warnings |
|-------|--------|----------|
| Full plugin | ~2320 | ~911 |
| Service + key Admin/Woo | ~506 | ~26 |

Not gating releases. See [BETA_READINESS.md](BETA_READINESS.md).

### i18n

| Item | Status |
|------|--------|
| `languages/mp-commerce-promotions.pot` | **Present** (~6000+ lines, `wp i18n make-pot`) |
| Bundled translations | None |

---

## QA status

| Area | Status |
|------|--------|
| Classic checkout (WP-CLI) | **Pass** — integrity, stacking, shipping smokes |
| Classic checkout (browser) | **Pass** with caveats — order **4339**, stacked fees, reversal; [BETA_RELEASE_DECISION.md](BETA_RELEASE_DECISION.md) |
| Cart/Checkout Blocks | **Not declared**; draft QA pages 4333/4334 |
| HPOS | **Declared** compatible |
| Production biopentra checkout | **Blocked** — BTCPay |

Docs: [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md), [BROWSER_QA_RUNBOOK.md](BROWSER_QA_RUNBOOK.md), [BLOCK_CHECKOUT_INVESTIGATION.md](BLOCK_CHECKOUT_INVESTIGATION.md).

---

## Known limitations

- Fee-based discounts (not catalog line prices)
- No partial refund reversal
- Block checkout unverified
- 100+ active promotions increase planner cost
- PHPCS baseline not clean

---

## Release blockers (before tag)

1. [x] **Browser sign-off** on classic checkout with COD — 2026-05-17 ([CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md))
2. [ ] **Product owner** approves **ready with caveats** ([BETA_RELEASE_DECISION.md](BETA_RELEASE_DECISION.md))
3. [ ] **Version bump** in code + tag ([VERSION_BUMP_PLAN_0.2.0_BETA1.md](VERSION_BUMP_PLAN_0.2.0_BETA1.md))
4. [ ] **Block decision** — declare blocks only if investigation passes; else document waiver for beta
5. [ ] **CHANGELOG** section for `0.2.0-beta.1` finalized
6. [ ] **Git tag** `v0.2.0-beta.1` + GitHub release asset (zip)

---

## Recommended beta audience

- WooCommerce merchants on **classic shortcode** cart/checkout
- **HPOS** enabled stores
- Teams accepting **manual** promotion operations and **fee-based** discounts
- Staging/pilot — **not** general wordpress.org distribution yet

**Not recommended for:** Block-only checkout, crypto-only checkout QA without staging clone, high-volume catalog without performance review.

---

## Rollback plan

1. Deactivate plugin or enable **Safe mode** + disable cart discounts.
2. Revert to previous zip / git tag.
3. Database: default **retain data** on uninstall; redemptions remain unless opt-in delete.
4. See [BETA_READINESS.md](BETA_READINESS.md) emergency checklist.

---

## Artifacts

| Artifact | Path |
|----------|------|
| Release zip | `../build/mp-commerce-promotions-0.1.0.zip` (version bumps with tag) |
| Support bundle | Diagnostics → Export (admin) |
