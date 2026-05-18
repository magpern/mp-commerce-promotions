# Gift card delivery emails

## Templates (slug stored in settings)

| Slug | Style |
|------|--------|
| `classic` | Default branded header |
| `birthday` | Birthday label |
| `holiday` | Holiday accent border |
| `minimal` | Light header, accent text |

No image files are generated or stored — only the template slug and HTML at send time.

## Content

- Responsive HTML email (`wp_mail` multipart-style HTML)
- Plain-text fallback if HTML send fails
- Amount, code, expiry, recipient/purchaser/message
- Redeem-at-checkout instructions
- Optional logo URL, accent color, support footer

## Email sender (Commerce Growth → Settings)

**Gift card email sender** controls the From/Reply-To headers:

| Mode | Behavior |
|------|----------|
| **Default** (recommended) | No `From` header is set by the plugin. WooCommerce, WP Mail SMTP, or your site mail configuration chooses the sender. Use this unless your SMTP provider has authorized a specific address. |
| **Custom** | Sets `From` (name + email) and optional `Reply-To` when the custom email is valid. If the email is invalid, settings save falls back to **Default** and an admin warning is shown. |

SMTP providers often reject custom From addresses that are not owned by the authenticated mailbox (for example “Sender address rejected: not owned by user …”). **Default mode avoids that class of failure.**

## Preview

Product page **Email preview** uses masked `****SAMPLE` only.

## Testing gift card email

1. Enable **Send gift card codes by email** in Settings.
2. Prefer **Default** sender mode unless SMTP authorizes your custom address.
3. Open **Diagnostics → Gift card email deliverability**.
4. Use **Send test gift card email** — sample code `****TEST` only; no gift card is created. The notice shows the effective sender mode used.
5. For live QA on this site, use a verified-domain recipient (documented in [GIFT_CARD_QA_EVIDENCE.md](GIFT_CARD_QA_EVIDENCE.md)). Do not use `example.com` addresses with real SMTP.

CLI smoke:

```bash
./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-mail-smoke.php
```

## Manual admin issue

Issuing a gift card with **recipient email** sends through the same mailer. Without a recipient, the admin screen shows **Email not sent: no recipient email** (code shown once only).

## Deliverability diagnostics

- **Diagnostics → Gift card email deliverability** — sender mode, custom sender warnings, recent `delivery_failed` count, last `wp_mail` failure, SMTP plugin hint
- Admin warning when mail is likely failing: **Configure SMTP before selling gift cards.**
- Support bundle includes `gift_card_mail` (no secrets)

## Security

- Full codes appear only in the email body at send time
- Never logged, never stored in order meta after delivery
- Audit log does not record plain codes
