# Release evidence — 0.2.0-beta.1

**Document date:** 2026-05-17  
**Plugin version in tree:** 0.2.0-beta.1  
**Target tag:** `v0.2.0-beta.1`  
**Schema:** 1.14.0 (unchanged)  
**Product owner approved:** **pending**

---

## Source control

| Item | Value |
|------|--------|
| **QA baseline commit** | `43d8b63` — classic checkout browser QA + recording fix |
| **Release commit** | `04000d7` — `chore: release 0.2.0-beta.1` |
| **Repository** | https://github.com/magpern/mp-commerce-promotions |
| **Branch** | `main` |

---

## Automated verification (2026-05-17, staging + live)

| Check | Result |
|-------|--------|
| `composer validate --strict` | **Pass** (Docker `composer:2`) |
| `composer run lint:php` | **Pass** |
| `composer run test` | **Pass** — 324 tests, 683 assertions, 1 skipped |
| `composer run lint:phpcs` | **Non-zero** (exit 2); non-gating |
| `scripts/build-zip.sh` | **Pass** → `mp-commerce-promotions-0.2.0-beta.1.zip` |
| `scripts/release-audit.sh` | **Pass** (292 entries, schema 1.14.0) |
| `scripts/verify-plugin.sh` | **Partial** — activate/deactivate + schema OK; zip path expects pre-sync live version (use staging `release-audit.sh` for artifact) |
| `scripts/beta-readiness-smoke.php` | 15/15 (prior) |
| `scripts/beta-release-prep-smoke.php` | **16/16** after `sync-to-live.sh` (re-run on live) |

### PHPCS snapshot (informational)

| Scope | Errors | Warnings |
|-------|--------|----------|
| Full plugin | ~2320 | ~911 |
| Service + key Admin/Woo | ~506 | ~26 |

Not gating releases. See [BETA_READINESS.md](BETA_READINESS.md).

### i18n

| Item | Status |
|------|--------|
| `languages/mp-commerce-promotions.pot` | Present |
| Bundled translations | None |

---

## QA status

| Area | Status |
|------|--------|
| Classic checkout (WP-CLI) | **Pass** |
| Classic checkout (browser) | **Pass** with caveats — order **4339**, stacked fees, reversal |
| Cart/Checkout Blocks | **Not declared** |
| HPOS | **Declared** compatible |

Docs: [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md), [BETA_RELEASE_DECISION.md](BETA_RELEASE_DECISION.md).

---

## Known caveats (beta)

- **Audience:** Technical pilot on **classic shortcode** cart/checkout — not GA production.
- **HPOS:** Declared. **Cart/Checkout Blocks:** **Not** declared.
- **Browser QA:** Stacked checkout, COD, recording, reversal **passed**; scoped %, free gift, free shipping with paid shipping, budget/cooldown, CSV export **partial** or **not run**.
- **Discount model:** Negative cart fees (and gift lines); not native line-item/coupon discounts.
- **PHPCS:** Non-gating; baseline not clean.
- **Schema:** 1.14.0 — no migration in this release.

---

## Release blockers

1. [x] Browser sign-off on classic checkout with COD — 2026-05-17
2. [ ] **Product owner** approves **ready with caveats** — **pending**
3. [x] Version bump in code + changelog finalized
4. [x] Block decision — **no** `cart_checkout_blocks` declaration (documented waiver)
5. [x] CHANGELOG `0.2.0-beta.1` section finalized
6. [ ] **Git tag** `v0.2.0-beta.1` — **pending owner approval** (do not tag until approval is **yes**)

---

## Artifacts

| Artifact | Path |
|----------|------|
| Release zip | `/home/magpern/mp-commerce-promotions-staging/build/mp-commerce-promotions-0.2.0-beta.1.zip` |

---

## Tag instructions (after product owner approval)

```bash
cd /home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions
git tag -a v0.2.0-beta.1 -m "Release 0.2.0-beta.1"
git push origin v0.2.0-beta.1
```

Attach `../build/mp-commerce-promotions-0.2.0-beta.1.zip` to the GitHub Release.

---

## Rollback plan

1. Deactivate plugin or enable **Safe mode** + disable cart discounts.
2. Revert to `v0.1.0` zip or previous commit.
3. Database: default **retain data** on uninstall.
4. See [BETA_READINESS.md](BETA_READINESS.md).
