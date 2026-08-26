# Authentication and Email Approvals

## Signed email approvals

Action-required email contains a signed review link. Opening it never mutates workflow state: the recipient sees a confirmation page, then explicitly submits the signed approval action. This prevents mail security scanners and link-preview crawlers from accidentally approving a submission.

Administrators choose the lifetime under **Settings → Approval links**. The accepted range is 1–720 hours and the default is 72 hours. A link is rejected when its signature expires, its workflow step is no longer current, or its named user is not a current direct/delegated approver. Successful actions still pass through `WorkflowEngine`, so locking, duplicate prevention, and the append-only action log remain intact.

Because the URL grants the named approver's authority for one current step, mail transport must use HTTPS and recipients should not forward the message.

### Optional current-session second factor

Administrators can enable **Require sign-in and a second factor for approval links** under **Settings → Approval settings**. The absent/default setting is off for v2.5.4 upgrade compatibility.

When enabled, signature validation runs before any authentication redirect. A valid link then requires an active authenticated account whose ID exactly matches the signed approver, current-step policy authorization, and a user-bound assurance marker in the same session. Fortify authenticator/recovery-code success and Laravel passkey verification establish the marker; merely having a factor enrolled does not establish it in a new session. The confirmation GET stays non-mutating and the approval POST remains signed, throttled, CSRF-protected, and processed by `WorkflowEngine`.

Enabling or disabling the option immediately affects links already issued but not expired. Messages that open `email-approvals/*`, including email, Telegram, and WhatsApp links, inherit the gate. Direct Teams and Slack action tokens use another endpoint and are not covered by this setting.

## Google login

Create an OAuth web application in Google Cloud and register this exact callback:

```text
https://your-approvedesk-domain.example/auth/google/callback
```

Configure:

```dotenv
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://your-approvedesk-domain.example/auth/google/callback
```

## Microsoft login

Create a web app registration in Microsoft Entra ID, add the callback below, and create a client secret:

```text
https://your-approvedesk-domain.example/auth/microsoft/callback
```

Configure:

```dotenv
MICROSOFT_CLIENT_ID=...
MICROSOFT_CLIENT_SECRET=...
MICROSOFT_REDIRECT_URI=https://your-approvedesk-domain.example/auth/microsoft/callback
MICROSOFT_TENANT=organizations
```

Use `common` for Microsoft personal and work accounts, `organizations` for work/school accounts, `consumers` for personal accounts, or a verified tenant ID/domain for a single tenant. A provider button appears only when both its client ID and secret are configured.

## Account linking rules

- Existing users are linked by normalized email after the provider identity is verified.
- A provider identity can link to only one ApproveDesk account, and each account can link only one identity from each provider.
- New users are created as active, verified `submitter` users only when public registration is enabled.
- Disabled ApproveDesk accounts cannot sign in through an external provider.
- Access and refresh tokens are not stored; the app uses the authorization code only to obtain the identity profile.

Always set `APP_URL` to the public HTTPS origin. If a redirect URI variable is omitted, ApproveDesk derives it from `APP_URL`.

