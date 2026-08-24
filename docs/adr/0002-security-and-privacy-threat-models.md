# 0002. Security and privacy threat models

Date: 2026-08-24

Status: Accepted

## Context

Phase 9.2 requires formal threat models for authentication, files, integrations,
workflow actions, exports, and upcoming SaaS tenancy before multi-tenant work
begins. This ADR records the assets, threats, and the mitigations shipped in
9.2. It does not reopen workflow-engine invariants or Phase 9.1 records.

## Decision

Treat the following surfaces as in-scope for 9.2. Residual risk that needs
tenant isolation is accepted until Phase 10.

### Authentication

| Threat | Mitigation |
|---|---|
| Password guessing | Configurable login rate limit; 2FA; passkeys |
| Stolen session | Device list and revoke; password change; suspicious-login notice |
| Session fixation / CSRF | Framework session regeneration; CSRF except signed inbound webhooks |
| Inactive account reuse | `is_active` check on password login; anonymize instead of reuse |
| OTP / registration abuse | Existing OTP and resend limiters |

### Files

| Threat | Mitigation |
|---|---|
| Direct URL guessing | Private disk + signed temporary URLs |
| Shared signed URL | Policy still required (`downloadFiles`) |
| Forwarded attachment | Optional download stamp (image overlay or zip notice) |
| MIME confusion | `X-Content-Type-Options: nosniff`; preview allow-list |

### Integrations

| Threat | Mitigation |
|---|---|
| Stolen connector secret | Encrypted credentials; rotate by re-saving; webhook `secrets:rotate-webhooks` |
| Replay / flood | Existing signatures; inbound webhook rate-limit profile |
| Error leakage | Existing redacted connector errors |

### Workflow actions

| Threat | Mitigation |
|---|---|
| Forged approval | Engine lock + current-step check; signed email/chat actions |
| Audit rewrite | `workflow_actions` append-only |
| Stale / tampered link | Existing signed-action tests |
| Actor identity loss | Anonymize keeps `actor_id`; name becomes “Former user” |

### Exports

| Threat | Mitigation |
|---|---|
| Sensitive field dump | `is_sensitive` + `ExportRedactor`; evidence packs redact without permission |
| Bulk scrape | Export rate-limit profile |
| Evidence-pack leak | Existing signed pack + auditor grant |

### Upcoming SaaS tenancy

| Threat | Mitigation now | Phase 10 |
|---|---|---|
| Cross-org data read | Single-org install; fail closed later | `organization_id` on every tenant table |
| Shared cache / files | Documented residual | Scope keys and storage paths |
| Platform operator access | Super-admin is org-local today | Split platform vs tenant roles |

## Consequences

- CSP and security headers are global. Inline Livewire/Alpine/Flux scripts
  require `'unsafe-inline'` / `'unsafe-eval'` until a nonce pass exists.
- Rate-limit defaults match the previous hardcoded values.
- Visual PDF stamping is not required when Imagick is absent; zip + notice is
  the portable fallback.
- Tenancy isolation tests remain Phase 10 work. This ADR is the accepted
  residual-risk record for 9.2.
