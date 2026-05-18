# PHPCS baseline (advisory)

**Policy:** `composer run lint:phpcs` is **advisory** in GitHub Actions until a 1.0 / GA cleanup pass. It must **not** block merges or release tags.

**Hard CI gates:** `composer validate --strict`, `composer run lint:php`, `composer run test`, release zip build (see `.github/workflows/ci.yml`).

## CI behavior

```yaml
- name: PHPCS advisory
  continue-on-error: true
  run: composer run lint:phpcs || true
```

The step always prints WPCS output for visibility. The job and workflow stay green when PHPUnit and PHP lint pass.

**Local:** Run `composer run lint:phpcs` to see the real exit code (often non-zero). Do not use `composer run lint` for PHPCS — the `lint` script runs **PHP syntax lint only**.

## Known baseline categories

| Category | Status | Notes |
|----------|--------|--------|
| **Prefix / namespace** | Deferred | `MP_` constants vs WPCS minimum prefix length; `MP\CommercePromotions\` PSR-4 namespace vs “prefixed namespace” sniff. Excluded in `phpcs.xml.dist` until 1.0 (no rename planned for pilot). |
| **Scripts / smoke** | Backlog | WP-CLI `scripts/*.php` — CLI entrypoints, nonce exceptions, empty catches. Relax or path-exclude later. |
| **Tests** | Backlog | `tests/` stubs and test doubles. May get a separate ruleset or exclusions before GA. |
| **Docblocks / style** | Backlog | Partially excluded in `phpcs.xml.dist` (WordPress-Docs). Remaining Squiz/Generic style noise. |
| **Direct DB / unprepared SQL** | Justified where present | Schema, migrations, `DbQuery`, repositories, uninstall cleaner use `phpcs:ignore` with comments. **Not** excluded globally — new code should use `DbQuery` or documented ignores. |
| **Uninstall** | Justified | `uninstall.php` / `UninstallDataCleaner` require direct DDL when opt-in delete is enabled. |

## What we are not doing (pilot / pre-1.0)

- Renaming the `MP\CommercePromotions` namespace
- Mass PHPCBF across `src/`
- Hiding `WordPress.DB.*` sniffs globally
- Making PHPCS a required check on `main`

## Target before GA (1.0)

1. Agree error budget (e.g. zero errors in `src/Admin`, `src/Service`, `src/Woo` target paths).
2. Re-enable prefix sniffs or document permanent exceptions in this file.
3. Tighten `scripts/` and `tests/` rules or exclusions explicitly.
4. Flip CI to **gating** `lint:phpcs` (remove `continue-on-error` and `|| true`) when the baseline is clean.

## Related docs

- [DEVELOPMENT.md](DEVELOPMENT.md) — local lint commands
- [BETA_READINESS.md](BETA_READINESS.md) — certification policy
- [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) — record PHPCS counts in release notes when cutting versions
