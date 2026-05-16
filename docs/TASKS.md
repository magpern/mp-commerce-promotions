# MP Commerce Promotions — Tasks

Living task list for development priorities. See [ARCHITECTURE.md](ARCHITECTURE.md) for system design and [README.md](../README.md) for current MVP behavior.

## Current milestone

**Marketplace / readiness foundation** — distribution scaffolding (`readme.txt`, `LICENSE`, `uninstall.php`, plugin headers, i18n bootstrap, task tracking) without changing promotion runtime behavior.

## Recently completed

- **Code quality tooling** — `composer.json`, `phpcs.xml.dist`, `docs/DEVELOPMENT.md` (PHPCS baseline cleanup still in progress)
- Admin UX bundle: duplicate promotion, edit screen layout, standardized notices
- Simple Rule Builder v0 and product/category ID helper
- Rule templates, validation panel, list search/filters/pagination
- Promotion codes, batches, redemptions, reversal, diagnostics
- Architecture guide (`docs/ARCHITECTURE.md`)
- Marketplace readiness scaffolding (this milestone)

## Next planned

1. **PHPCS / WPCS baseline** — incremental fixes on existing codebase (config in `phpcs.xml.dist`; see [DEVELOPMENT.md](DEVELOPMENT.md))
2. **PHPUnit smoke tests** — evaluator, repositories, duplication service
3. **CI workflow** — lint + test on push
4. **Composer autoloading** — optional PSR-4 via Composer for releases
5. **Promotion mechanics (Phase C)** — free shipping, BOGO, stackability (see architecture roadmap)

## Backlog

### Admin UX

- Reusable admin table/notice components
- `WP_List_Table` migration for promotions list
- Product/category search in rule builder (no AJAX scope creep without design)
- Usage limit field on promotion edit form
- Screenshots for readme.txt

### Promotion engine

- Additional conditions (first order, customer role, country)
- Additional actions (free shipping, line-item targeting)
- Stackability and conflict resolution
- Replace or supplement negative-fee strategy (see architecture risk note)

### Code / voucher system

- Persistent secure code export policy (if ever required — security review first)
- PDF / email delivery
- Partner/reseller code pools
- Scheduled batch jobs

### Reporting / diagnostics

- Diagnostics pagination beyond 100 rows
- Redemption / campaign reporting dashboard
- Scheduled maintenance (not automatic repair without explicit opt-in)

### Marketplace readiness

- Semantic versioning and release tags
- `composer.json` and release zip packaging
- Full GPLv2 text in LICENSE (optional; notice + URI currently used)
- Uninstall “delete all data” setting with confirmation
- Compatibility matrix documentation
- WooCommerce HPOS compatibility declaration review
- WordPress.org / WooCommerce Marketplace submission checklist (when mature)

## Do not start without review

The following are **out of scope** for opportunistic implementation. Require explicit product/architecture approval:

| Topic | Reason |
|-------|--------|
| **BOGO / free product actions** | New action types, cart line manipulation, testing matrix |
| **REST API** | Public surface, auth, versioning, support burden |
| **Partner / reseller system** | Multi-tenant ownership, code pools, reporting |
| **Gift cards / store credit** | Different domain model and liability |
| **Checkout discount strategy replacement** | Replaces fee-based MVP; tax/reporting/compliance impact |

Also avoid without review: destructive uninstall defaults, storing plaintext codes, automatic usage repair cron, and multi-promotion stacking without conflict rules.
