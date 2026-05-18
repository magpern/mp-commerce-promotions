# Manual test: Campaign Builder

Merchant-facing layer on top of the existing promotion engine. Creates **normal draft promotions**; the **Advanced editor** remains available for raw JSON, orchestration, and diagnostics.

## URL

Default (no `tab`):

`wp-admin/admin.php?page=mp-commerce-promotions`

Explicit tab:

`wp-admin/admin.php?page=mp-commerce-promotions&tab=campaign-builder`

With a goal selected (wizard step):

`...&tab=campaign-builder&campaign_goal=category_discount&cb_step=targeting`

## Layout (guided merchant cockpit)

- **Simple Campaign Builder** / **Advanced editor** (expert mode) switch
- Progress bar: Goal → Targeting → Offer → Schedule → Review
- Step 1: goal grid with color themes and merchant teasers
- Steps 2–5: wizard with Back/Continue; state preserved via transient token (`cb_token`, 1 hour)
- Searchable category/product pickers (AJAX) + manual ID fallback
- Preview card: headline, confidence badge, timeline, benefit, targeting, limits, smart advice
- Recent campaigns: summary column, lifecycle chips, budget progress bars
- Styles scoped to `.mp-cb-wrap` via `assets/css/admin-campaign-builder.css` + `admin-campaign-builder.js`

## Checklist

1. **Navigation** — WooCommerce → Promotions opens Campaign Builder by default. Tabs: Getting Started → Campaign Builder → Advanced Promotions → Reports → Diagnostics → Settings.
2. **Summary cards** — Horizontal row; icons; counts; links open filtered list; warning styling when needs attention / budget exhausted > 0.
3. **Goal cards** — All 10 goals visible with title, description, “Best for”, Create link.
4. **Category discount** — Select categories, 20% off, label, start/end, budget, usage limit, stackable Yes, coupon No → review → **Create draft campaign**.
5. **Success** — Draft created message, name shown, no auto-activation; View / Advanced edit / Activate / Create another links work.
6. **Coupon campaign** — Generate code; code shown once on success; code works in Advanced Editor codes section.
7. **Preview sidebar** — Applies when / Customer receives / Limits / Stacking / Coupon text updates; warnings for missing end date or overlaps when applicable.
8. **Latest campaigns table** — Shows recent promotions with goal label, health, View, Advanced edit, Duplicate.
9. **Advanced escape hatch** — Expert mode / Advanced editor link visible on builder and success screens.
10. **Engine parity** — Open Advanced editor: conditions/actions match template expectations; status remains `draft`.

## Session expiry

If `cb_token` is missing or expired after idle time, a warning appears and fields fall back to defaults — pick the goal again to restart the wizard.

## QA evidence

See `docs/CAMPAIGN_BUILDER_QA_EVIDENCE.md` for goal-by-goal pass/fail notes and promotion IDs from the latest QA pass.

## Automated

```bash
composer run lint:php
composer run test -- --filter CampaignSummaryFormatter
composer run test -- --filter CampaignBuilder
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-builder-smoke.php
```
