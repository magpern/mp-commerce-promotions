# Pilot release: 0.3.0-pilot.2

**Plugin version:** `0.3.0-pilot.2`  
**Schema version:** `1.17.0` (unchanged — no migration in this release)  
**Release type:** Pilot (pre-GA)  
**Tag:** `v0.3.0-pilot.2`

## Supersedes 0.3.0-pilot.1

| Item | Detail |
|------|--------|
| **Superseded version** | `0.3.0-pilot.1` |
| **Reason** | Campaign Builder **default-route rendering regression**: opening **WooCommerce → Promotions** without `tab=campaign-builder` highlighted Campaign Builder but did not enqueue builder CSS/JS. |
| **Fix** | `AdminNavigation::normalize_tab()` + `CampaignBuilderPage` assets use `get_current_tab()` (commit `10b95f2`). |
| **Upgrade** | Replace pilot.1 installs with **0.3.0-pilot.2** before any merchant pilot. Pilot.1 GitHub Release remains for audit; do not deploy pilot.1. |

## Purpose

Ship a **merchant-pilot-ready** build with guided **Campaign Builder** as the default admin entrypoint (fully styled on the default URL), plus **Advanced Promotions** for expert mode.

## Supported checkout modes

| Mode | Status |
|------|--------|
| Classic shortcode cart/checkout | Supported |
| Cart/Checkout Blocks | Supported — `cart_checkout_blocks` declared |
| HPOS | Declared compatible |

**Default storefront discount:** fee-based cart fees. **Line item / hybrid** modes remain **experimental**.

## Certified areas (pilot scope)

Same as pilot.1 — see [CAMPAIGN_BUILDER_QA_EVIDENCE.md](CAMPAIGN_BUILDER_QA_EVIDENCE.md). Default admin route verified via `scripts/commerce-growth-navigation-smoke.php`.

## Known limitations

- **Pilot / not GA** — not marketplace or accounting certified
- **Line discount mode** — experimental
- **PHPCS** — advisory only in CI
- **0.3.0-pilot.1** — superseded; do not use

## Installation

1. Download **`mp-commerce-promotions-0.3.0-pilot.2.zip`** from the [GitHub Release](https://github.com/magpern/mp-commerce-promotions/releases/tag/v0.3.0-pilot.2) or run `bash scripts/build-zip.sh`.
2. Upload via **Plugins → Add New → Upload**, or extract to `wp-content/plugins/mp-commerce-promotions/`.
3. Activate and open **WooCommerce → Promotions** (no `tab` required — Campaign Builder must render with full styling).
4. Optional: `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pilot-release-smoke.php`

## Rollback

Deactivate and restore previous plugin folder from backup. Schema unchanged at **1.17.0**.

## Pilot checklist

- [ ] Confirm **Promotions** menu loads styled Campaign Builder without `&tab=campaign-builder`
- [ ] Confirm explicit `&tab=campaign-builder` matches default URL
- [ ] Run `commerce-growth-navigation-smoke.php` and `campaign-builder-smoke.php`
- [ ] Complete checkout smoke on classic and Blocks if applicable

## Emergency disable / safe mode

See [PILOT_RELEASE_0.3.0_PILOT1.md](PILOT_RELEASE_0.3.0_PILOT1.md) (operations sections unchanged) and [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md).

## Verification commands

```bash
composer validate --strict
composer run lint:php
composer run test
bash scripts/build-zip.sh
bash scripts/release-audit.sh
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/commerce-growth-navigation-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-builder-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pilot-release-smoke.php
```
