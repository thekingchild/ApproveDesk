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
`'unsafe-eval'` for the current Livewire/Alpine/Flux frontend. When an
administrator saves analytics or custom code, HTTPS hosts found in that
snippet are added to `script-src`, `connect-src`, `img-src`, and `frame-src`
so common tracking providers can load. Empty custom code leaves the policy
unchanged.

## Rate-limit profiles

Defaults (per minute): login 5, API 60, approval links 10, exports 10,
webhooks 60, integrations 60. Administrators change them on
Settings → Security & privacy. Values are stored in the `settings` table.

## Signed approval links

Signed approval URLs remain time-limited, throttled, current-step checked, and confirmation based. Administrators may additionally require the named approver to sign in and verify an authenticator/recovery code or passkey in the current session. This option is off by default and is stored in the existing settings table.

The route validates the signature before preserving an intended login destination. With enforcement on, the authenticated account must exactly match the signed approver and remain active and authorized. The confirmation GET never mutates workflow state; the POST remains CSRF-protected and uses the transactional `WorkflowEngine` path. Do not log the URL, signature, factor code, passkey credential, or session identifier.

Direct Teams and Slack action tokens do not enter the web approval route and are not covered by this option.
