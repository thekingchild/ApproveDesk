# 0003. Optional current-session assurance for signed approval URLs

Date: 2026-08-26

Status: Accepted

## Context

ApproveDesk v2.5.4 lets the person holding a valid, unexpired signed approval URL review and confirm an action without an authenticated application session. Signature validation, expiry, throttling, current-step authorization, confirmation POST, and the workflow engine limit abuse, but a forwarded or compromised message still carries temporary bearer authority.

Some self-hosted organizations prefer the convenience of that flow. Others require the application account and its second factor to be the approval identity. The control therefore needs to be optional and must not invalidate the existing release tag or silently break upgraded installations.

## Decision

- Add `approvals.email_require_second_factor` to the existing settings table. The absent/default value is off, preserving v2.5.4 behavior. No schema migration is required.
- Keep the signed URL and its configured expiry. When enforcement is on, it becomes an authenticated entry point rather than sufficient approval authority.
- Validate the signature before any login redirect. Never preserve a tampered or expired URL as an intended destination.
- Require an active authenticated account whose identifier exactly matches the signed `{approver}` route model and which remains authorized for the current workflow step.
- Require second-factor assurance in the current session. A successful Fortify authenticator/recovery-code challenge or Laravel passkey ceremony may establish assurance for that user and session. Enrollment stored on the account is not enough for a new session.
- Existing sessions have no assurance marker after upgrade and must complete a factor ceremony or sign in again. A marker for another user, a malformed marker, or a new session does not satisfy the gate.
- Enabling or disabling the option applies immediately to links already issued but not expired. The administrator interface warns that disabling makes those links usable without sign-in again.
- Keep the confirmation GET non-mutating and the signed, throttled, CSRF-protected POST on the existing policy and `WorkflowEngine` path. `workflow_actions` remains append-only.
- Keep `{{approval_url}}` in action-required templates. Email, Telegram, WhatsApp, and other notifications that open the shared `email-approvals/*` route inherit this enforcement.
- Direct Teams and Slack action tokens do not enter that route and are outside v2.5.5. Their residual bearer-token behavior must be disclosed rather than represented as covered.
- Log only generic result codes and internal record identifiers. Never log full URLs, query strings, signatures, factor/recovery codes, passkey credential identifiers, or session identifiers.

## Consequences

- Organizations can choose stronger signer identity assurance without forcing a breaking change on existing installations.
- Protected links add login and factor ceremony friction, especially on a new browser or expired session.
- Users without an enrolled factor must enroll before they can approve through a protected link.
- The link lifetime remains a separate defense-in-depth control.
- Teams and Slack direct-action assurance requires a separate follow-up if an organization wants every out-of-app channel to follow the same policy.