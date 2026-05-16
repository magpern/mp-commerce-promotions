# Development guide

Local tooling and verification for **Commerce Promotions for WooCommerce**. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design and [TASKS.md](TASKS.md) for priorities.

## Repository layout

| Path | Role |
|------|------|
| `/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions` | Git working tree (commit here) |
| `/home/magpern/woocommerce/wp-content/plugins/mp-commerce-promotions` | Live WordPress plugin directory (sync target) |

**Sync rule:** copy files from staging to live with `tar` (or rsync). **Never** copy `.git/` or `vendor/` into the live plugin directory unless you intentionally install Composer dependencies there (not required for runtime).

```bash
tar -C /home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions \
  -cf - --exclude='.git' --exclude='vendor' . \
  | tar -C /home/magpern/woocommerce/wp-content/plugins/mp-commerce-promotions -xf -
```

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
3. **Domain / engine docblocks** — only where low risk
4. **WooCommerce integration** — last; highest regression risk

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

GitHub Actions workflow for lint is **deferred** until PHPCS has a manageable baseline or CI is configured to run syntax-only checks first. See [TASKS.md](TASKS.md) — “CI workflow” is next after incremental PHPCS cleanup.

## What not to change in tooling-only commits

- Promotion evaluation or checkout behavior
- Database schema or migrations
- Replacing `src/autoload.php` with Composer autoload without an explicit migration task
