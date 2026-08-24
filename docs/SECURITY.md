# Security operations

Operator procedures that do not belong in the in-app Help centre.

## Secret rotation

| Secret | How to rotate |
|---|---|
| Application key | `php artisan key:generate` only on a fresh install, or decrypt/re-encrypt every encrypted column first. Do not rotate `APP_KEY` on a live database without a backup. |
| Mail / OAuth client secrets | Change the value at the provider, then update `.env` and run `php artisan config:clear`. |
| Outbound webhook signing secrets | `php artisan secrets:rotate-webhooks` (or pass an endpoint ID). Copy the printed secret to the receiving system immediately. The previous secret stops working. |
| Integration connector credentials | Administration → Integrations → save new credentials. Old tokens are overwritten. |
| Notification channel tokens | Settings → Notification channels → post new credentials. |
| Evidence-pack signing key | Stored in application key material. Rotating `APP_KEY` invalidates existing pack signatures; download a fresh pack after a planned key change. |
| Personal API tokens | The owner revokes the token on Settings → API tokens and creates a new one. |

After any rotation, confirm `/up` and a test sign-in. Keep the previous secret only for the documented overlap window of the receiving system.

## Security headers

Every response includes `Content-Security-Policy`, `X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and
`Cross-Origin-Opener-Policy`. HTTPS responses also send
`Strict-Transport-Security`. The CSP allows `'unsafe-inline'` and
`'unsafe-eval'` for the current Livewire/Alpine/Flux frontend.

## Rate-limit profiles

Defaults (per minute): login 5, API 60, approval links 10, exports 10,
webhooks 60, integrations 60. Administrators change them on
Settings → Security & privacy. Values are stored in the `settings` table.
