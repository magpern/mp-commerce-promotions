# Pilot release: 0.3.0-pilot.1

**Plugin version:** `0.3.0-pilot.1`  
**Schema version:** `1.17.0` (unchanged — no migration in this release)  
**Release type:** Pilot (pre-GA)  
**Tag:** `v0.3.0-pilot.1`

## Purpose

Ship a **merchant-pilot-ready** build of Commerce Promotions with the guided **Campaign Builder** as the default admin entrypoint, while keeping **Advanced Promotions** (expert mode) for raw JSON, orchestration, codes, and diagnostics.

This release is for **staged store pilots**, not marketplace distribution or a 1.0 GA claim.

## Supported checkout modes

| Mode | Status |
|------|--------|
| Classic shortcode cart/checkout | Supported (certified in beta cycle) |
| Cart/Checkout Blocks | Supported — `cart_checkout_blocks` declared; fee + coupon paths certified |
| HPOS (`custom_order_tables`) | Declared compatible |

**Default storefront discount path:** fee-based cart fees. **Line item / hybrid** discount application remains **experimental**.

## Certified areas (pilot scope)

- Campaign Builder — 10 goals, wizard, draft creation, QA evidence (`docs/CAMPAIGN_BUILDER_QA_EVIDENCE.md`)
- Promotion planner, stacking, exclusions, redemption record/reverse
- Promotion codes (hashed), batches (show-once)
- Reports, Diagnostics, support bundle export
- Safe mode, degraded storefront mode, optional cron (off by default)
- Blocks fee + coupon COD certification (see `docs/BLOCKS_QA_EVIDENCE_2026-05-18.md`)

## Known limitations

- **Pilot / not GA** — no accounting-grade or marketplace certification
- **Line discount mode** — experimental; use fee-based unless you complete `docs/manual-line-discount-engine-test.md`
- **PHPCS** — advisory only in CI (not merge-blocking)
- **Partial refunds** — reversal is all-or-nothing per promotion on an order
- **Generated codes** — plaintext shown once; not recoverable
- **Scale** — many active promotions increase planner work; use safe mode under load
- **Third-party stacks** — Subscriptions/Bundles/Germanized not production-certified at scale

## Installation

1. Download **`mp-commerce-promotions-0.3.0-pilot.1.zip`** from the GitHub Release (or build with `bash scripts/build-zip.sh`).
2. Upload via **Plugins → Add New → Upload**, or extract to `wp-content/plugins/mp-commerce-promotions/`.
3. Activate **Commerce Promotions for WooCommerce** (WooCommerce 8.0+ required).
4. Open **WooCommerce → Promotions** — lands on **Campaign Builder**.
5. Confirm **Settings → Enable cart discounts** is on for storefront testing.
6. Run `./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pilot-release-smoke.php` on the target site (optional).

## Rollback

1. **Deactivate** the plugin (data retained by default).
2. Replace the plugin folder with the previous zip version, or restore from backup.
3. **Re-activate** — schema is unchanged at `1.17.0`; no downgrade migration required.
4. If promotions were created only as **drafts**, storefront impact is nil until activation.

For emergencies without redeploying code, use **Diagnostics → Emergency operations** and **Settings → Safe mode** (see below).

## Pilot checklist

- [ ] WooCommerce + HPOS state documented for the pilot store
- [ ] Campaign Builder smoke passes on staging
- [ ] One campaign created per priority goal (draft → review → activate)
- [ ] Classic **and** Blocks checkout tested with a real payment method (COD acceptable on staging)
- [ ] Reports show redemptions after test orders
- [ ] Support bundle exported and stored securely
- [ ] Merchant trained: Campaign Builder first; Advanced editor for experts only
- [ ] Rollback zip and DB backup identified before go-live

## Emergency disable / safe mode

| Action | Where |
|--------|--------|
| Stop storefront discounts | **Promotions → Settings** — disable cart discounts, or `add_filter( 'mp_cp_enable_cart_discounts', '__return_false' );` |
| Safe mode (pause automation/telemetry pressure) | **Settings** — production safety / safe mode |
| Pause all active promotions | **Diagnostics → Emergency operations** (dry-run first) |
| Per-promotion dry-run | Edit promotion — **Dry run** checkbox (no fees/gifts/line changes) |

See [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) and [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md).

## Support bundle

1. **WooCommerce → Promotions → Diagnostics**
2. Scroll to **Support bundle** (or export section)
3. Download JSON — contains versions, compatibility snapshot, redacted diagnostics (no customer PII)
4. Attach to pilot support tickets; do not commit to public repos

## Remaining 1.0 blockers

See [GA_READINESS_DELTA.md](GA_READINESS_DELTA.md). Summary:

1. PHPCS gating policy and error budget on core paths
2. Sustained load baselines per store profile (not synthetic-only)
3. Line/hybrid mode: certification or remain disabled by default for GA
4. Fresh browser certification records within 30 days for all four areas
5. Merchant onboarding fully aligned with Campaign Builder (addressed in this pilot for admin; storefront docs still evolving)

## Verification commands

```bash
composer validate --strict
composer run lint:php
composer run test
bash scripts/build-zip.sh
bash scripts/release-audit.sh
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/pilot-release-smoke.php
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-builder-smoke.php
```
