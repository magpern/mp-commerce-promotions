# Pilot release: 0.3.0-pilot.3

**Plugin version:** `0.3.0-pilot.3`  
**Schema version:** `1.19.0` (unchanged — no migration in this release)  
**Release type:** Pilot (pre-GA, controlled testing)  
**Tag:** `v0.3.0-pilot.3`

## Supersedes 0.3.0-pilot.2

| Item | Detail |
|------|--------|
| **Superseded version** | `0.3.0-pilot.2` |
| **Reason** | Merchant admin UI exposed internal pilot/checklist language; this build uses production-safe operational copy only. |
| **Schema** | No change — remain on **1.19.0** |
| **Upgrade** | Replace pilot.2 with **0.3.0-pilot.3** for new pilot stores |

## Purpose

Ship a **merchant-facing pilot build** for real store testing: Commerce Growth shell, Campaign Builder, gift cards/store credit, and operational warnings without internal “pilot checklist” links in admin UI.

## What changed in pilot.3 (vs pilot.2)

- Removed **Pilot checklist** shortcut from Gift Cards dashboard.
- Reworded gift card **email delivery** and **ledger export** notices (no “pilot sales” / “pilot warning”).
- Gift Cards shortcuts now include **Reports** and **Diagnostics**.
- Diagnostics **rollback** section titled **Rollback and performance profiles** (functionality unchanged).
- Version bump only — no promotion engine or ledger logic changes.

## Supported checkout modes

| Mode | Status |
|------|--------|
| Classic shortcode cart/checkout | Supported |
| Cart/Checkout Blocks | Supported |
| HPOS | Declared compatible |

**Default discount application:** fee-based. Line item / hybrid modes remain **experimental**.

## Installation

1. Download **`mp-commerce-promotions-0.3.0-pilot.3.zip`** from the GitHub Release or run `bash scripts/build-zip.sh`.
2. Upload via **Plugins → Add New → Upload**, or extract to `wp-content/plugins/mp-commerce-promotions/`.
3. Activate; open **WooCommerce → Commerce Growth**.
4. Before selling gift cards: configure SMTP, send test email, export ledger CSV periodically.

## Rollback

Deactivate and restore previous plugin folder from backup. Schema unchanged at **1.19.0**.

## Internal QA (repository only)

- [GIFT_CARD_PILOT_CHECKLIST.md](GIFT_CARD_PILOT_CHECKLIST.md) — kept for operators; **not linked from merchant UI**
- `scripts/pilot-release-smoke.php`, `scripts/commerce-growth-navigation-smoke.php`

## Emergency disable

See [MERCHANT_WORKFLOWS.md](MERCHANT_WORKFLOWS.md) — pause promotions, disable automatic promotions session flag, deactivate plugin if required.
