# Manual test: campaign operations

Schema **1.9.0** adds campaign metadata columns and archive hygiene tools. No storefront or checkout behavior changes.

## Prerequisites

- Plugin active; `./wp plugin list` shows `mp-commerce-promotions` active.
- `mp_cp_schema_version` = `1.9.0` after activation/migration.

## 1. Campaign metadata on edit screen

1. Edit any promotion.
2. Find **Campaign metadata** (after Promotion details).
3. Set:
   - **Campaign label:** `Summer Sale`
   - **Admin color:** `#336699`
   - **Internal notes:** `Internal team note`
4. Save promotion.
5. Reload page — fields persist; header shows label and color swatch.

**Pass:** Values saved; escaped display only (no HTML in notes).

## 2. All Promotions list

1. Open **WooCommerce → Promotions**.
2. Confirm **Campaign** column shows label and color badge.
3. Use **Campaign** dropdown filter (when labels exist) — list narrows to matching promotions.
4. Search box matches promotion name, UUID, or campaign label.

**Pass:** Filter and search work without full-table conflict analysis.

## 3. Reports filter

1. Open **Reports** tab.
2. Apply **Campaign label** filter (if labels exist).
3. Confirm **Top promotions** table includes **Campaign** column when data exists.

**Pass:** Redemption summaries respect campaign filter.

## 4. Archive hygiene (Diagnostics)

1. Open **Diagnostics** tab.
2. Section **Archive hygiene**:
   - Create an **active** promotion with `ends_at` in the past (or use smoke data).
   - Click **Archive expired active promotions** — confirm summary (archived / skipped / errors).
   - Click **Archive old drafts** with days `90` — only ancient drafts move to archived.

**Pass:** POST + nonce required; promotions become `archived`; audit `promotion.status_changed`; nothing deleted.

## 5. WP-CLI smoke

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-ops-smoke.php
```

**Pass:** Schema 1.9.0, metadata persistence, expired archive, filter by label.

## Limitations

- No hard delete — archive only.
- Campaign metadata is admin-only (not customer-facing).
- No REST/AJAX; standard form POST.
- `admin_color` must be `#RRGGBB` hex or empty.
- Archive helpers process up to 500 rows per run.
