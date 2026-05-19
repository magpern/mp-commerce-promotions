# QA and smoke script safety

WP-CLI scripts under `scripts/` can create WooCommerce products, orders, gift cards, promotions, and send email. **Production defaults block writes and outbound mail** unless you set explicit environment variables.

## Safe defaults

| Environment | Persistent writes | Email | Cleanup |
|-------------|-------------------|-------|---------|
| Local / staging / dev | Allowed | Suppressed | On (`MP_CP_QA_CLEANUP` default) |
| Production-like | **Blocked** | **Suppressed** | N/A (script exits) |

Production-like means `WP_ENVIRONMENT_TYPE=production` (or unset on a non-local URL). URLs containing `localhost`, `.test`, `.local`, or `staging` are treated as non-production.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `MP_CP_ALLOW_LIVE_QA=1` | Allow smoke scripts that **create** data on production |
| `MP_CP_ALLOW_PERSISTENT_QA_SETUP=1` | Allow setup scripts (`gift-card-product-setup.php`, etc.) on production |
| `MP_CP_ALLOW_QA_EMAILS=1` | Allow real `wp_mail` during QA scripts |
| `MP_CP_QA_DRY_RUN=1` | Force dry-run (setup scripts log intent, skip writes) |
| `MP_CP_QA_CLEANUP=0` | Disable automatic tagged cleanup at script end |
| `MP_CP_QA_APPLY=1` | Allow persistent QA scripts to write on production (otherwise dry-run) |
| `MP_CP_PRODUCTION_DATA_RESET=1` | Allow destructive production data reset script |
| `MP_CP_PRODUCTION_DATA_RESET_APPLY=1` | Actually delete during reset (with reset flag + live QA) |

Admin **Send test gift card email** (merchant click) is unchanged — only automated scripts respect `MP_CP_ALLOW_QA_EMAILS`.

## Running locally / staging

```bash
cd /path/to/woocommerce
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/shipping-qualified-subtotal-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/qa-runtime-guard-smoke.php
```

Created products/orders/promotions are tagged with `_mp_cp_qa_created=yes` and `_mp_cp_qa_run_id` and removed on shutdown when cleanup is enabled.

## Running on production (intentional)

```bash
export MP_CP_ALLOW_LIVE_QA=1
# optional, only if you need real delivery:
# export MP_CP_ALLOW_QA_EMAILS=1

./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-e2e-smoke.php
```

**Do not** run persistent setup on production without:

```bash
export MP_CP_ALLOW_PERSISTENT_QA_SETUP=1
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-product-setup.php
```

## Implementation

- `src/Qa/QaRuntimeGuard.php` — environment + env flags
- `src/Qa/QaCleanupRegistry.php` — tagged cleanup
- `src/Qa/QaEmailSuppression.php` — `pre_wp_mail` short-circuit for scripts only
- `scripts/lib/qa-bootstrap.php` — include from every `*smoke*.php` / `*qa*.php` script
- `scripts/lib/qa-script-manifest.php` — per-script capabilities (readonly vs persistent vs email)

## Script authors

1. Start scripts with `require_once __DIR__ . '/lib/qa-bootstrap.php'; mp_cp_qa_bootstrap_script( __FILE__ );`
2. Call `$qa = mp_cp_qa_context(); $qa->register_product( $id );` (and order/promotion/gift card as needed)
3. Use `$qa->qa_note( 'detail' )` for ledger notes containing the run ID
4. Check `$qa->is_dry_run()` before irreversible writes when supporting dry-run
