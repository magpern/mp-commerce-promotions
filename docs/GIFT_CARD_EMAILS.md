# Gift card delivery emails

## One configurable template

Gift card emails use a **single classic Commerce Growth layout**. Merchants customize copy and appearance under **Gift Cards & Store Credit → Settings** — not multiple admin “template styles.”

**Customer-facing gift card design themes** (birthday, holiday, etc.) are planned as a future purchaser option at checkout, not as global admin mail settings.

Legacy template slugs (`birthday`, `holiday`, `minimal`) in the database are **not deleted**; delivery always uses the classic layout.

## Email style

| Mode | Behavior |
|------|----------|
| **Commerce Growth template** (default) | Full branded HTML from this plugin. |
| **WooCommerce email style** | Inner gift card content wrapped with WooCommerce email header, footer, and `style_inline` when `WC()->mailer()` is available. Falls back to Commerce Growth if WooCommerce mailer is unavailable. |

## Merchant-editable fields

| Field | Default (placeholders allowed) |
|-------|--------------------------------|
| **Subject** | Your gift card from {site_title} |
| **Email heading** | You received a gift card |
| **Intro / body** | A gift card has been sent to you. You can use it toward eligible purchases in our store. |
| **Redeem instructions** | Enter your gift card code during checkout in the “Gift card or store credit” section. |
| **Footer text** | Keep this email safe. The full gift card code is required at checkout and is not stored after delivery. |
| **Support contact text** | Need help? Contact our support team. |
| **Logo** | Optional — WordPress media library picker or manual URL |
| **Accent color** | WordPress color picker; defaults to WooCommerce email base color, then theme accent/link, then `#2271b1` |

### Placeholders

`{site_title}`, `{amount}`, `{currency}`, `{code}`, `{expiry}`, `{recipient_name}`, `{purchaser_name}`, `{message}`, `{store_url}`

## Settings UI

`?page=mp-commerce-promotions&tab=gift-cards&gift_cards_section=settings`

- **Gift card email** — subject, heading, intro, redeem text, footer, support, logo (media picker), accent (color picker)
- **Email style** — Commerce Growth vs WooCommerce wrapper
- **Reset gift card email template** — restores production default copy, clears logo, resets accent to WooCommerce email / theme / `#2271b1`, and Commerce Growth email style. Does **not** change sender mode, custom sender, reply-to, or delivery toggles.
- **Live preview** — updates as you type; uses `****SAMPLE` only (never a real code)
- **Send test gift card email** — uses current form field values via AJAX (save not required)

### Media picker and color picker

On `?page=mp-commerce-promotions&tab=gift-cards&gift_cards_section=settings`:

- **Choose logo** opens the WordPress media modal; selected image URL fills the logo field and updates the preview.
- **Accent color** uses the WordPress color picker (`wp-color-picker`); invalid hex falls back to the resolved default.
- If `wp.media` is unavailable, an inline admin notice explains that a manual URL can be used instead.

Known smoke/test strings from development (e.g. `Merchant QA subject line`, `Smoke persist subject`, accent `#aa5500`) are replaced automatically with production defaults when read; merchant-customized text is never changed unless it exactly matches a known QA string.

## Email sender

See previous sender documentation (Default vs Custom). Default mode is recommended for SMTP compatibility.

## Preview and test safety

- Settings and product previews use masked `****SAMPLE` only.
- Test emails use `****TEST` only; no ledger card is created.
- Full codes are never logged or persisted after delivery.

## Testing

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-mail-smoke.php
```

## Manual admin issue

Issuing with a recipient email uses the same mailer and merchant copy. Without a recipient, the admin screen shows **Email not sent: no recipient email**.

## Deliverability diagnostics

**Diagnostics → Gift card email deliverability** — email style, Woo availability, sender mode, SMTP hints, test email.
