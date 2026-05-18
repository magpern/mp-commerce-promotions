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
- Custom From name/email when configured

## Preview

Product page **Email preview** uses masked `****SAMPLE` only.

## Deliverability diagnostics

- **Diagnostics → Gift card email deliverability** — recent `delivery_failed` count, last `wp_mail` failure transient, SMTP plugin hint, redacted settings summary
- Admin warning when mail is likely failing: **Configure SMTP before selling gift cards.**
- Support bundle includes `gift_card_mail` (no secrets)

## Security

- Full codes appear only in the email body at send time
- Never logged, never stored in order meta after delivery
- Audit log does not record plain codes
