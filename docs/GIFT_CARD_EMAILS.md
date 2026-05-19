# Gift card delivery emails

## Templates (slug stored in settings)

| Slug | Style |
|------|--------|
| `classic` | Default branded header |
| `birthday` | Birthday label |
| `holiday` | Holiday accent border |
| `minimal` | Light header, accent text |

No image files are generated or stored — only the template slug and HTML at send time.

Per-template overrides (logo, accent, footer, support) are stored in **Commerce Growth → Gift Cards & Store Credit → Settings** for the selected template style. Platform-wide Commerce Growth toggles remain under **Commerce Growth → Settings**.

## Email style

| Mode | Behavior |
|------|----------|
| **Commerce Growth template** (default) | Full branded HTML from this plugin (classic / birthday / holiday / minimal). |
| **WooCommerce email style** | Inner gift card content wrapped with WooCommerce email header, footer, and `style_inline` when `WC()->mailer()` is available. Uses WooCommerce → Settings → Emails base/body/background colors where configured. Falls back to Commerce Growth template if WooCommerce mailer is unavailable. |

## Content

- Responsive HTML email (`wp_mail` multipart-style HTML)
- Plain-text fallback if HTML send fails
- Amount, code, expiry, recipient/purchaser/message
- Redeem-at-checkout instructions
- Optional logo URL, accent color, footer text, support contact text

## Settings UI (Gift Cards & Store Credit → Settings)

`?page=mp-commerce-promotions&tab=gift-cards&gift_cards_section=settings`

- **Template style** — classic, birthday, holiday, minimal
- **Email style** — Commerce Growth vs WooCommerce wrapper
- **Logo URL, accent color, footer text, support contact** — saved for the selected template
- **Template preview** — live HTML preview using `****SAMPLE` only (never a real code)
- **Send test gift card email** — recipient, sample amount, currency; sends `****TEST` only

## Email sender

**Gift card email sender** controls the From/Reply-To headers:

| Mode | Behavior |
|------|----------|
| **Default** (recommended) | No `From` header is set by the plugin. WooCommerce, WP Mail SMTP, or your site mail configuration chooses the sender. Use this unless your SMTP provider has authorized a specific address. |
| **Custom** | Sets `From` (name + email) and optional `Reply-To` when the custom email is valid. If the email is invalid, settings save falls back to **Default** and an admin warning is shown. |

SMTP providers often reject custom From addresses that are not owned by the authenticated mailbox. **Default mode avoids that class of failure.** Custom sender requires SMTP authorization for the From address.

## Preview

- **Settings** and **product page** previews use masked `****SAMPLE` only.
- Template preview never loads or displays a real gift card code from the database.

## Testing gift card email

1. Enable **Send gift card codes by email** under **Gift Cards & Store Credit → Settings**.
2. Prefer **Default** sender mode unless SMTP authorizes your custom address.
3. Use **Gift Cards & Store Credit → Settings → Preview selected template** and **Send test gift card email** (sample `****TEST` only).
4. Open **Diagnostics → Gift card email deliverability** for sender mode, template, Woo style availability, SMTP hint, last failure, and test email.

CLI smoke:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-mail-smoke.php
```

For live QA, use a verified-domain recipient (see [GIFT_CARD_QA_EVIDENCE.md](GIFT_CARD_QA_EVIDENCE.md)). Do not use `example.com` with real SMTP.

## Manual admin issue

Issuing a gift card with **recipient email** sends through the same mailer. Without a recipient, the admin screen shows **Email not sent: no recipient email** (code shown once only).

## Deliverability diagnostics

- **Diagnostics → Gift card email deliverability** — active template, email style, Woo style available, sender mode, SMTP likely working, recent failures, last `wp_mail` failure, test email button
- Admin warning when mail is likely failing: **Configure SMTP before selling gift cards.**
- Support bundle includes `gift_card_mail` (no secrets)

## Security

- Full codes appear only in the email body at send time
- Never logged, never stored in order meta after delivery
- Audit log does not record plain codes
- Previews and test emails use sample codes only
