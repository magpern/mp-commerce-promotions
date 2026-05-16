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

## PHPCS baseline (expected failures)

As of the tooling introduction commit, **PHPCS is expected to report many violations** on the existing MVP codebase (naming, namespaces, `$wpdb->prepare` patterns, missing docblocks, etc.). First run baseline (52 files scanned): **365 errors, 262 warnings**; PHPCBF can auto-fix ~221 sniff issues. This task adds configuration only — **not** a full style fix pass.

Workflow:

1. Run `composer run lint:php` — should pass (syntax only).
2. Run `composer run lint:phpcs` — review the summary; fix violations incrementally in follow-up commits.
3. Do not block releases on a clean PHPCS run until a baseline cleanup milestone is scheduled (see [TASKS.md](TASKS.md)).

Configured standards:

- WordPress, WordPress-Extra, WordPress-Docs (selected doc rules excluded as noisy)
- PHPCompatibilityWP (PHP 7.4+)
- Text domain: `mp-commerce-promotions`
- Global prefixes: `mp_cp`, `mp_commerce_promotions`, `MP`

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
