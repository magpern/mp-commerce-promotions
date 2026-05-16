# Development guide

Local tooling and verification for **Commerce Promotions for WooCommerce**. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design and [TASKS.md](TASKS.md) for priorities.

## Repository layout

| Path | Role |
|------|------|
| `/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions` | Git working tree (commit here) |
| `/home/magpern/woocommerce/wp-content/plugins/mp-commerce-promotions` | Live WordPress plugin directory (host bind mount; sync target) |
| `scripts/sync-to-live.sh` | Safe staging → container sync (excludes dev paths) |
| `scripts/verify-plugin.sh` | Post-sync WP-CLI checks |
| `scripts/build-zip.sh` | Distributable zip under `../build/` (excludes dev paths) |
| [CHANGELOG.md](../CHANGELOG.md) | Release notes (Keep a Changelog) |
| [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) | Version bump, tag, zip, sync, manual tests |

### Staging → live sync

From the plugin root (staging tree):

```bash
bash scripts/sync-to-live.sh
```

The script streams the staging working tree into container `woocommerce-wordpress-1` at `/var/www/html/wp-content/plugins/mp-commerce-promotions`, excludes `.git`, `vendor`, `node_modules`, and PHPCS/PHPUnit caches, removes any of those paths if they were left on target from an earlier copy, sets `www-data:www-data` ownership, and verifies the main plugin file exists.

**Warning:** **Never** copy `.git/` or `vendor/` into the live plugin directory. `vendor/` is for **development tooling only** (PHPCS, WPCS). Production/runtime loads classes via `src/autoload.php`, not Composer autoload.

### Post-sync verification

```bash
bash scripts/verify-plugin.sh
```

Runs `./wp plugin status`, deactivate/activate cycle, status again, and prints `mp_cp_schema_version` (read-only; no destructive DB operations).

### Release zip

From the plugin root:

```bash
bash scripts/build-zip.sh
```

Writes `mp-commerce-promotions-{version}.zip` to `/home/magpern/mp-commerce-promotions-staging/build/`, where `{version}` is read from `MP_COMMERCE_PROMOTIONS_VERSION` (must match the plugin header `Version:`). The archive root folder is `mp-commerce-promotions/`. Excludes `.git`, `vendor`, `node_modules`, and PHPCS/PHPUnit caches.

Full release steps: [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md). History: [CHANGELOG.md](../CHANGELOG.md).

**Plugin version** (`0.1.0` today) is independent of **schema version** (`Schema::SCHEMA_VERSION` / `mp_cp_schema_version`).

## Composer (tooling only)

Composer manages **development dependencies** (PHPCS, WPCS, PHPCompatibility). The plugin still boots via `src/autoload.php` at runtime — **Composer autoload is not used in production** yet.

### Install dev dependencies

From the plugin root:

```bash
composer install
```

Requires [Composer](https://getcomposer.org/) on the machine running the command (host or a one-off Docker Composer image).

`composer.lock` is **ignored** in git for this phase so local installs may resolve slightly different patch versions; pin with a committed lockfile later when CI is stable.

### Lint commands

| Command | Purpose |
|---------|---------|
| `composer run lint:php` | `php -l` on all plugin `.php` files (excludes `vendor/`, `node_modules/`) |
| `composer run lint:phpcs` | PHPCS using `phpcs.xml.dist` |
| `composer run lint` | Both of the above |
| `composer run test` | PHPUnit unit tests (`phpunit.xml.dist`) |
| `composer validate` | Validate `composer.json` |

Direct PHPCS (after `composer install`):

```bash
vendor/bin/phpcs
```

## PHPCS baseline and cleanup strategy

### Initial baseline (tooling commit `5402dd4`)

First run on the MVP codebase (52 files): **365 errors, 262 warnings**. PHPCBF could auto-fix ~221 sniff issues.

### Incremental batches (recommended)

Clean up PHPCS in **small, reviewable commits** grouped by concern:

1. **Admin / infrastructure** — shared helpers, alignment, escaping (this milestone)
2. **Repositories** — `$wpdb->prepare` patterns and SQL docblocks (verify behavior; no logic changes)
3. **Domain / engine** — `RuleTypes` / `RuleRegistry` for supported condition/action IDs; docblocks and alignment where low risk (see [ARCHITECTURE.md](ARCHITECTURE.md#promotion-rule-engine))
4. **WooCommerce integration** — hook clarity and helpers; highest regression risk for checkout behavior

After each batch:

```bash
composer run lint:php
composer run lint:phpcs
```

Record the summary line (`A TOTAL OF X ERRORS AND Y WARNINGS`) in the commit message or PR notes when helpful.

### Intentionally deferred (do not mass-fix yet)

| Category | Reason |
|----------|--------|
| PSR-4 namespace prefix (`MP\CommercePromotions\…`) | Architecture uses namespaces; WPCS expects a slug-style prefix on every namespace |
| `WordPress.DB.PreparedSQL.*` in dynamic repository SQL | Needs case-by-case review; wrong `prepare()` can break queries |
| Missing class/function docblocks across domain/engine | Noisy; excluded partially in `phpcs.xml.dist` |
| Yoda conditions | Excluded in ruleset; do not convert wholesale |
| Array alignment-only diffs | Large diffs with little runtime value |

### Guidance for contributors and AI sessions

- Prefer **targeted fixes** (one sniff, one directory) over formatting the entire tree.
- Avoid **formatting-only commits** that touch dozens of files without functional or standards benefit.
- Do not change promotion evaluation, checkout, or schema in PHPCS-only work.
- Run `./wp plugin deactivate` / `activate` and a quick admin smoke test after admin refactors.

Workflow:

1. Run `composer run lint:php` — should pass (syntax only).
2. Run `composer run lint:phpcs` — review the summary; fix violations incrementally.
3. Do not block releases on a clean PHPCS run until baseline cleanup and CI are scheduled (see [TASKS.md](TASKS.md)).

Configured standards:

- WordPress, WordPress-Extra, WordPress-Docs (selected doc rules excluded as noisy)
- PHPCompatibilityWP (PHP 7.4+)
- Text domain: `mp-commerce-promotions`
- Global prefixes: `mp_cp`, `mp_commerce_promotions`, `MP`

## Reusable admin helpers

| Class | Role |
|-------|------|
| `AdminNotice` | Escaped admin notices (success, warning, error, info) |
| `AdminSection` | Titled `.card` sections on the promotion edit screen |
| `AdminUrl` | List, edit, tab, settings, and diagnostics URLs |

## Repository SQL pattern

Domain repositories use custom tables defined in `Schema` only.

| Rule | Detail |
|------|--------|
| Table names | Always from `Schema::*_table( $wpdb )`, then `TableName::assert_valid()` before interpolating into SQL |
| Values | Always via `$wpdb->prepare()` placeholders (`%s`, `%d`, …) — use `DbQuery::prepare()` / `get_row()` / `get_var()` / `get_results()` / `query()` |
| LIKE | Wrap user search with `$wpdb->esc_like()` before binding `%s` |
| LIMIT / OFFSET | Cast and clamp integers, then pass as `%d` placeholders |
| Never prepare table names | Identifiers are validated, not placeholder-bound |
| Woo/core meta tables | When joined (e.g. `postmeta`, `wc_orders_meta`), validate with `TableName::assert_valid()` the same way |

`DbQuery` centralizes the PHPCS-safe prepare → execute flow so repositories do not repeat `phpcs:ignore` on every `get_var( $prepared )` call.

## Docker / WP-CLI verification

Project WordPress root: `/home/magpern/woocommerce`

```bash
cd /home/magpern/woocommerce
docker compose ps
./wp plugin deactivate mp-commerce-promotions
./wp plugin activate mp-commerce-promotions
./wp plugin status mp-commerce-promotions
```

PHP syntax inside the WordPress container (no Composer required):

```bash
docker exec woocommerce-wordpress-1 sh -c \
  'find /var/www/html/wp-content/plugins/mp-commerce-promotions -name "*.php" -print0 | xargs -0 -n1 php -l'
```

## Continuous integration

Workflow: [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) on `push` / `pull_request` to `main`.

| Step | Enforced |
|------|----------|
| `composer validate --strict` | Yes |
| `composer install` | Yes |
| `composer run lint:php` | Yes (`php -l` on all plugin `.php` files) |
| `composer run test` | Yes (PHPUnit unit tests in `tests/Unit`) |
| `bash scripts/build-zip.sh` | Yes (artifact path verified) |
| `composer run lint:phpcs` | **No** — not run in CI yet |

**PHP matrix:** 7.4, 8.1, 8.2 (matches `composer.json` `require.php`).

PHPCS is installed via Composer for local incremental cleanup; the baseline is **not clean** (see PHPCS section above). PHPCS will become a **gating** CI step after the remaining batches land and the team agrees on an acceptable error budget (or a committed baseline file).

## PHPUnit (unit tests only)

Configuration: `phpunit.xml.dist`, bootstrap `tests/bootstrap.php` (defines `ABSPATH` / `MP_COMMERCE_PROMOTIONS_PATH` and loads `src/autoload.php` **without** WordPress).

```bash
composer install
composer run test
```

**Scope today:** pure PHP classes under domain/engine/services that do not call WordPress APIs — including `PromotionEvaluator`, `EvaluationResult` / `ConditionTrace` / `ActionTrace`, `PromotionRuleValidator`, `SimpleRuleBuilder`, `Promotion`, `RuleRegistry`, discount actions, customer conditions (`logged_in`, `first_order`, `customer_role`, `billing_country`, `customer_email_domain`), and `EvaluationContext`. `CartContextBuilder` is exercised in production only; unit tests supply metadata manually.

**Evaluation trace:** `PromotionEvaluator::evaluate()` populates `condition_traces` and `action_traces` on every result. Use `EvaluationResult::to_array()` or getters in tests/WP-CLI smoke checks. Reason codes are stable internal strings — do not rename without a changelog note.

**Future work:** WordPress/WooCommerce integration tests (bootstrap WP test suite, repositories against test DB, checkout flows) are **not** implemented yet.

Run the same checks locally before pushing:

```bash
composer validate --strict
composer install
composer run lint:php
composer run test
bash scripts/build-zip.sh
```

## What not to change in tooling-only commits

- Promotion evaluation or checkout behavior
- Database schema or migrations
- Replacing `src/autoload.php` with Composer autoload without an explicit migration task
