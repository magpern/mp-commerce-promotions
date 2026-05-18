# Manual test: Campaign Builder

Merchant-facing layer on top of the existing promotion engine. Creates **normal draft promotions**; the Advanced Editor remains the source of truth for JSON, diagnostics, and orchestration.

## URL

`wp-admin/admin.php?page=mp-commerce-promotions&tab=campaign-builder`

With a goal selected:

`...&tab=campaign-builder&campaign_goal=category_discount`

## Checklist

1. **Navigation** — Tabs order: Getting Started → Campaign Builder → All Promotions → Reports → Diagnostics → Settings.
2. **Summary cards** — Active, Scheduled, Drafts, Needs attention, Budget exhausted show counts (no errors).
3. **Goal cards** — All 10 goals visible with title, description, “Best for”, Create link.
4. **Category discount** — Select categories, 20% off, label, start/end, budget, usage limit, stackable Yes, coupon No → Create Draft Campaign.
5. **Success** — Draft created message, name shown, no auto-activation; View / Advanced edit / Activate / Create another links work.
6. **Coupon campaign** — Generate code; code shown once on success; code works in Advanced Editor codes section.
7. **Preview sidebar** — Applies when / Customer receives / Limits / Stacking / Coupon text updates; warnings for missing end date or overlaps when applicable.
8. **Latest campaigns table** — Shows recent promotions with goal label, health, View, Advanced edit, Duplicate.
9. **Advanced escape hatch** — “Need more power? Use Advanced Editor” visible on builder and success screens.
10. **Engine parity** — Open Advanced edit: conditions/actions match template expectations; status remains `draft`.

## Automated

```bash
composer run test -- --filter CampaignBuilder
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-builder-smoke.php
```
