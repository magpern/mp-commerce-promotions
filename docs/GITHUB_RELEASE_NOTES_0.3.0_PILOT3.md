# Commerce Promotions for WooCommerce 0.3.0-pilot.3

**Pilot prerelease** for controlled merchant testing — supersedes **0.3.0-pilot.2**.

## Highlights

- **Commerce Growth admin shell** — WooCommerce submenu and screens branded **Commerce Growth**; Campaign Builder is the default entrypoint.
- **Campaign Builder** — guided wizard, ten goals, draft promotion creation.
- **Advanced Promotions** — expert list/edit mode (`tab=all`).
- **Gift Cards & Store Credit** — issue/manage gift cards, customer wallets, ledger export, email settings, scheduled delivery, product-backed gift cards.
- **Store credit wallet** — customer accounts, checkout application, admin grant/deduct (schema **1.19.0**).
- **Customer experience** — balance checker, My Account areas, HTML email templates, transfer and scheduled delivery.
- **Email configuration** — sender settings, classic template, live preview, test email, diagnostics mail summary.
- **Merchant-facing admin copy** — internal “pilot checklist” / “pilot warning” wording removed from runtime UI; operational warnings retained.

## Supersedes pilot.2

| Item | Detail |
|------|--------|
| **Superseded version** | `0.3.0-pilot.2` |
| **Schema** | **1.19.0** unchanged from pilot.2 installs that already migrated |
| **Upgrade** | Replace plugin folder or upload ZIP; no new migration required if already on schema ≥ 1.19.0 |

## Checkout compatibility

- Classic shortcode cart/checkout — supported
- Cart/Checkout Blocks — supported (`cart_checkout_blocks` declared)
- HPOS — declared compatible

## Known pilot limitations

- **Pilot software** — not GA or marketplace-certified; use staging and your own QA before production traffic.
- **Line item / hybrid discount modes** — experimental; fee-based remains default.
- **PHPCS** — advisory only in CI.
- **Gift card CSV export** — audit/reconciliation aid; not a full disaster-recovery import.
- **Accounting** — liability reports are operational aids, not certified financial statements.

## Installation

1. Download **`mp-commerce-promotions-0.3.0-pilot.3.zip`** from this release.
2. Install and activate; open **WooCommerce → Commerce Growth** (page slug `mp-commerce-promotions`).
3. Configure gift card email (SMTP) before selling gift card products.
4. Optional smoke: `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pilot-release-smoke.php`

## Upgrade from pilot.2

1. Deactivate (optional backup).
2. Replace the plugin directory or upload this ZIP over **0.3.0-pilot.2**.
3. Reactivate; flush permalinks if gift card balance pages 404.
4. Confirm Gift Cards dashboard shortcuts no longer show “Pilot checklist”.

## Documentation (repository)

Internal QA remains in-repo (`docs/GIFT_CARD_PILOT_CHECKLIST.md`, QA evidence) but is **not linked from merchant admin UI** in this build.

- [PILOT_RELEASE_0.3.0_PILOT3.md](docs/PILOT_RELEASE_0.3.0_PILOT3.md)
- [CHANGELOG.md](CHANGELOG.md)
