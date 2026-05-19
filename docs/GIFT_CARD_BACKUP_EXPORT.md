# Gift card & store credit — backup and CSV export

Use this before pilot sales and periodically during operations.

## What to back up

### Database (required for restore)

Normal **site and database backups** must include Commerce Growth gift card tables:

| Table | Purpose |
|-------|---------|
| `{prefix}mp_cp_gift_cards` | Card rows, balances, masked last4, hashed codes |
| `{prefix}mp_cp_gift_card_transactions` | Append-only ledger |

Without these tables, **full gift card codes cannot be reconstructed** from CSV exports alone (codes are stored as one-way hashes).

### CSV exports (audit / reconciliation)

From **Gift Cards & Store Credit → Dashboard** or **Settings**, merchants with `manage_woocommerce` can download:

| Export | Contents |
|--------|----------|
| **Export gift cards** | IDs, UUID, last4, masked code, balances, status, customer/order links — **no full code, no code_hash** |
| **Export transactions** | Ledger rows (issue, redeem, adjust, void, etc.) |
| **Export outstanding liability summary** | Active liability **grouped by currency** (gift card vs store credit) |

Exports are **POST-only**, nonce-protected, and logged to the audit trail as `gift_card.export_csv`.

The plugin records **last export timestamps** in `mp_cp_gift_card_export_timestamps`. **Diagnostics** warns when liability exists but no export was recorded in the last 30 days.

## What CSV exports are not

- **Not a full restore** — import is intentionally **not** included in the pilot.
- **Not a balance editor** — CSV cannot change balances; ledger rules stay in the app.
- **Not a code vault** — full codes are only shown at issue/delivery time, never in exports.

## Recommended pilot workflow

1. Confirm automated **database backups** include `mp_cp_gift_cards` and `mp_cp_gift_card_transactions`.
2. Run **Export gift cards** and **Export transactions**; store files securely (merchant finance / ops).
3. Run **Export outstanding liability summary** for a currency-level snapshot.
4. Complete [GIFT_CARD_PILOT_CHECKLIST.md](GIFT_CARD_PILOT_CHECKLIST.md).

## Verification

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-export-smoke.php
```

See also [GIFT_CARDS_STORE_CREDIT.md](GIFT_CARDS_STORE_CREDIT.md), [GIFT_CARD_QA_EVIDENCE.md](GIFT_CARD_QA_EVIDENCE.md).
