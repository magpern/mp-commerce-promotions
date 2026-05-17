# Version bump plan — 0.2.0-beta.1

Classic browser QA completed 2026-05-17 — recommendation **ready with caveats**. Version **0.2.0-beta.1** bumped in release commit; **git tag pending** product owner approval.

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
| `mp-commerce-promotions.php` | [x] Header `Version:` and `MP_COMMERCE_PROMOTIONS_VERSION` |
| `readme.txt` | [x] `Stable tag: trunk` + `= 0.2.0-beta.1 =` changelog |
| `README.md` | [x] Version and zip path |
| `CHANGELOG.md` | [x] `## [0.2.0-beta.1] - 2026-05-17` |
| `languages/mp-commerce-promotions.pot` | Optional — regenerate post-tag if needed |
| `docs/RELEASE_EVIDENCE_0.2.0_BETA1.md` | [x] Updated; release commit hash after push |

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
git commit -m "chore: release 0.2.0-beta.1"
git push origin main

# After product owner approval:
git tag -a v0.2.0-beta.1 -m "Release 0.2.0-beta.1"
git push origin v0.2.0-beta.1

# Attach ../build/mp-commerce-promotions-0.2.0-beta.1.zip to GitHub Release
```

---

## Preconditions (do not tag until done)

- [x] [CLASSIC_CHECKOUT_CERTIFICATION.md](CLASSIC_CHECKOUT_CERTIFICATION.md) browser sign-off — 2026-05-17
- [ ] Product owner approves [BETA_RELEASE_DECISION.md](BETA_RELEASE_DECISION.md) — **pending**
- [x] [RELEASE_EVIDENCE_0.2.0_BETA1.md](RELEASE_EVIDENCE_0.2.0_BETA1.md) updated for release
- [x] Block compatibility — **no** declaration (explicit waiver for beta)
- [ ] CI green on release commit (PHPUnit + zip; PHPCS may warn) — verify at release
- [ ] Git tag `v0.2.0-beta.1` — **pending** owner approval

---

## Post-tag

- Update [docs/TASKS.md](TASKS.md) milestone
- Notify beta testers with [BETA_READINESS.md](BETA_READINESS.md) limitations
- Sync live: `bash scripts/sync-to-live.sh` (staging tree only until version bump merged)
