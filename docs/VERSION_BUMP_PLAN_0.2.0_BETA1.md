# Version bump plan — 0.2.0-beta.1

**Do not bump version in code until browser QA is approved and product owner confirms the tag.**

---

## Target version

| Field | Value |
|-------|--------|
| **Semantic version** | `0.2.0-beta.1` |
| **Git tag** | `v0.2.0-beta.1` |
| **Stable readme tag** | `0.2.0-beta.1` (or `trunk` until wordpress.org — use beta for GitHub releases) |

**Schema:** Remain **1.14.0** unless a QA bug requires a migration.

---

## Files to update (checklist)

| File | What to change |
|------|----------------|
| `mp-commerce-promotions.php` | Header `Version:` and `MP_COMMERCE_PROMOTIONS_VERSION` |
| `readme.txt` | `Stable tag:` and changelog section |
| `README.md` | Version mentions if any |
| `CHANGELOG.md` | New `## [0.2.0-beta.1] - YYYY-MM-DD` section |
| `languages/mp-commerce-promotions.pot` | `Project-Id-Version` in header (regenerate via `wp i18n make-pot`) |
| `docs/RELEASE_EVIDENCE_0.2.0_BETA1.md` | Final commit hash |

---

## Build and tag commands

```bash
cd /home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions

# After version constants updated:
composer validate --strict
composer run lint:php
composer run test
composer run lint:phpcs   # informational
bash scripts/build-zip.sh
bash scripts/release-audit.sh
bash scripts/verify-plugin.sh

cd /home/magpern/woocommerce
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/beta-release-prep-smoke.php

git add -A
git commit -m "chore: release version 0.2.0-beta.1"
git tag -a v0.2.0-beta.1 -m "0.2.0-beta.1 — public beta"
git push origin main
git push origin v0.2.0-beta.1

# Attach ../build/mp-commerce-promotions-0.2.0-beta.1.zip to GitHub Release
```

---

## Preconditions (do not tag until done)

- [ ] [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md) browser sign-off completed
- [ ] [RELEASE_EVIDENCE_0.2.0_BETA1.md](RELEASE_EVIDENCE_0.2.0_BETA1.md) blockers cleared or waived in writing
- [ ] Block compatibility decision documented (declare or explicit waiver)
- [ ] CI green on release commit (PHPUnit + zip; PHPCS may warn)

---

## Post-tag

- Update [docs/TASKS.md](TASKS.md) milestone
- Notify beta testers with [BETA_READINESS.md](BETA_READINESS.md) limitations
- Sync live: `bash scripts/sync-to-live.sh` (staging tree only until version bump merged)
