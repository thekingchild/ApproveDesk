# Feature definitions

Short meanings for the words used on ApproveDesk screens. Open a term’s name to read the full guide, when one exists for your role.

## [Public website](/help/getting-around)

The marketing page visitors see at the site address before they sign in. Guests see **Log in** and, when self-registration is allowed, **Sign up**. If you are already signed in, that button becomes **Dashboard**.

## [Request](/help/requests)

A single case waiting for a decision. It has a form, optional files, comments, a status, and a workflow history. Requesters create requests. Approvers act on them.

## [Request type](/help/request-types)

A named form, such as Leave Request or Purchase Request. The request type is what a requester chooses. It owns the fields people fill in and the approval chain that starts when they submit.

## [Workflow template](/help/workflow-templates)

A named approval chain: the ordered steps, who acts, and what happens on timeout. Many templates can be active at once. A template does nothing until a request type is assigned to it, unless it is the default.

## [Workflow builder](/help/workflow-templates)

The canvas and list editor for a workflow template. Start and End are shown for orientation.

## [Workflow version](/help/workflow-templates)

One frozen copy of a workflow template’s steps. New requests use the published version. Requests already in flight keep the version they started, including after an upgrade.

## [Graph validation](/help/workflow-templates)

The checks that run before a workflow version is published: unreachable steps, cycles, missing assignees, impossible quorum, invalid branches, and unsafe timeouts.

## [Default template](/help/workflow-templates)

The one fallback chain. It runs for General Request and for any request type that has no assigned template, or whose assigned template is inactive. There can be only one default.

## [Workflow assignment](/help/request-types)

The link from a request type to a workflow template. New submissions of that form start that chain unless a selection rule matches first. Requests already in flight keep the chain they started with.

## [Selection rule](/help/request-types)

An ordered rule on a request type that chooses a workflow from form values. Priority 1 is evaluated first. The first match wins, then the assigned template, then the default.

## [Assignee picker](/help/workflow-templates)

The search field on a workflow step that finds users, roles, or departments without listing everyone. Selected people appear as chips.

## [Assignee resolver](/help/workflow-templates)

How a step decides who acts: named people, a role, department members, the requester's manager, the department manager, a person picked on the form, or another connected system.

## [Group completion](/help/workflow-templates)

How a step with more than one assignee finishes: first response, all assignees, or quorum N-of-M.

## [Notification channel](/help/notification-channels)

A delivery path for events such as “action required”. In-app and email are always on. Teams, Slack, WhatsApp, and Telegram are optional administrator channels. Browser push is opted in per device.

## [Email template](/help/notification-channels)

Organisation-owned subject and body for a notification kind. Generic copy is the fallback until an administrator saves an override. Action-required templates must keep `{{approval_url}}`.

## [WhatsApp template mapping](/help/notification-channels)

A link from an ApproveDesk notification kind to an approved WhatsApp Business template name, language, and placeholders. Unmapped or unapproved templates skip WhatsApp; ApproveDesk does not send free-form WhatsApp.

## [Telegram bot](/help/notification-channels)

An optional chat bot. People link their Telegram account; ApproveDesk sends a message and a review link. Approvals still happen in ApproveDesk, not inside the chat.

## [Branding](/help/organization-settings)

The organisation identity administrators set under **Settings → Branding**: application name, meta description, logo, favicon, login tagline, accent colour, and login background. The logo and name appear after sign-in; the background is the public login screen.

## [Business calendar](/help/organization-settings)

Working days, office hours, and holidays used when a step deadline is measured in business hours instead of elapsed hours. Empty office hours mean every hour of a working day counts; elapsed hours ignore the calendar.

## [Custom outcome](/help/workflow-templates)

An extra decision button, such as Approve with conditions. Each outcome maps to approve, reject, return, request information, or escalate.

## [Submission type](/help/request-types)

Document, Note, Memo, or Request. These are fields on the built-in General Request form. They are not a limit on how many templates you may create, and they do not reserve a workflow.

## [Status](/help/statuses)

The current state of a request: Draft, Pending, In review, Awaiting information, Returned, Approved, Rejected, or Withdrawn.

## [Field label](/help/request-types)

The name you type for a form field. ApproveDesk keeps that name attached to visibility rules and reports even if you later rename the field. You do not invent a technical name.

## [Follower](/help/collaboration)

Someone granted explicit view access to a request so they can open it and receive comment or status notifications. Followers are not approvers unless the workflow also assigns them. They can unfollow themselves.

## [Mention](/help/collaboration)

A named person in a comment. A mention notifies them and adds them as a follower so they can open the request.

## [Reminder](/help/collaboration)

A manual nudge to the current approver. It is recorded, rate limited, and delayed when reminder quiet hours are in effect.

## [Out of office](/help/collaboration)

A dated absence on your account, with an optional fallback approver. While it is active, current steps that allow delegation pass to the fallback.

## [Expected next step](/help/collaboration)

The next approval step the current request is expected to reach after the people on the current step decide. It is a preview, not a change to the published workflow.

## [Estimated due time](/help/collaboration)

The clock for the current step’s timeout, using elapsed hours or the business calendar (working days, office hours, holidays). It updates if the business calendar changes.

## [Audit trail](/help/audit-trail)

The history of decisions on a request. ApproveDesk does not change or remove those entries.

## [Confirmation code (OTP)](/help/organization-settings)

A 6-digit number emailed to someone who self-registers when **Require email confirmation** is on. It expires in 10 minutes and can be used once. Sign-in is blocked until they enter it.

## [Domain allow-list](/help/organization-settings)

The list of email domains permitted to self-register, set on Branding. Empty means any provider. New Google and Microsoft accounts follow the same list. It does not apply to accounts an administrator creates or imports, or to linking Google or Microsoft on an account that already exists.

## [User import](/help/users-and-roles)

A spreadsheet upload on **Administration → Users** that creates many accounts at once after a dry-run preview. Passwords are generated, not imported.

## [Integration hub](/help/integrations)

The administrator screen for connectors and post-approval actions (upload a file, send mail, update an external record, or call a signed webhook). Failed deliveries can be replayed.

## [Connector](/help/integrations)

One configured destination in the integration hub, such as Slack or generic HTTP. Credentials stay hidden after you save. Destination URLs must be public HTTPS. A disabled connector is skipped.

## [Replay](/help/operations)

Send a failed webhook, integration action, notice, or queue job again. Replay does not create a second record and does not change the approval. Integration actions can also be replayed from the integration hub.

## [Offline draft](/help/requests)

A copy of a draft kept on this device when the form cannot reach ApproveDesk. If it differs from the saved draft after you reconnect, you choose which to keep.

## [Signed approval action](/help/approvals)

The email link or Teams/Slack **Approve** button for the current step. Tampered, expired, or stale actions are refused and do not change the request.

## [Evidence pack](/help/audit-trail)

A signed download of one request: the form, workflow version, actions, comments, delegations, notifications, file hashes, and timestamps. Verification detects a changed file.

## [Auditor access](/help/records)

A time-bounded, read-only grant. The person can open requests and download evidence packs until the grant ends or is revoked. They cannot approve or change settings.

## [Legal hold](/help/records)

A flag on a request that blocks automatic deletion until someone releases it.

## [Retention](/help/records)

How long drafts and finished requests are kept before they may be removed.

## [Disposition](/help/records)

A review that must be approved before a finished request past its retention window is removed.

## [Sensitive field](/help/request-types)

A form answer hidden from people who do not have the sensitive-fields ability. The requester still sees their own answer. Opening it is recorded.

## [Download stamp](/help/security-and-privacy)

A mark on a downloaded file that names who took it and when. Images show the stamp; other files arrive with a notice.

## [Device session](/help/account-settings)

A signed-in browser listed on **Settings → Security**. You can sign one out or sign out every other device.

## [Suspicious sign-in](/help/account-settings)

A sign-in from a device or location not seen before. ApproveDesk emails you and writes an in-app notice.

## [Rate limit](/help/security-and-privacy)

How many times sign-in, the API, approval links, exports, webhooks, or integrations may be tried each minute.

## [Second factor requirement](/help/security-and-privacy)

An organization setting that sends people to **Settings → Security** until they confirm an authenticator or add a passkey. Off until an enrolled administrator turns it on.

## [Approval-link second factor](/help/organization-settings)

An optional approval setting that keeps the signed, expiring link but requires its named approver to sign in and verify an authenticator, recovery code, or passkey in the current browser session.

## [Turnstile check](/help/security-and-privacy)

An optional challenge on sign-in, registration, and password reset that stops automated guessing. Off until an administrator saves a site key and secret and turns it on.

## [My data export](/help/account-settings)

A zip of your profile, requests, comments, and sign-in history from **Settings → Privacy**. Secrets are not included.

## [Account anonymization](/help/users-and-roles)

An administrator action that removes a person’s name and email, blocks sign-in, and keeps their request history.

## [Operations](/help/operations)

The administrator screen for scheduled-work health, failed deliveries, stuck requests, and a check that this copy still has its required records.

## [Scheduler heartbeat](/help/operations)

The last time the minute job that drains waiting work recorded that it ran. A stale heartbeat means waiting notices and deliveries may be sitting.

## [Failed delivery](/help/operations)

A webhook, integration action, notice, or queue job that stopped after retries. **Replay** sends the same item again without creating a second record or changing the approval.

## [Stuck request](/help/operations)

An active request with no current step, or a step that has waited more than a day. Opening it does not decide or escalate.

## [Backup check](/help/operations)

A confirmation that this copy still has the required records. It does not take a snapshot or a host backup and it does not overwrite anything.

## [Backup](/help/backups)

A snapshot of either the database or the uploaded files. The two kinds stay in separate archives.

## [Database backup](/help/backups)

A snapshot of the records. It does not include uploaded files.

## [Files backup](/help/backups)

A snapshot of uploaded request files and branding artwork. It does not include the database.

## [Backup destination](/help/backups)

Where a snapshot is stored: the local disk, an S3-compatible bucket, or Google Drive.

## [Refresh token](/help/backups)

A Google sign-in code that lets ApproveDesk copy snapshots to Drive on a schedule without opening a browser. Paste it on the Google Drive card.

## [Retain](/help/backups)

How many days a snapshot is kept. The last successful copy of each kind is never removed.

## [Promote](/help/backups)

Putting a verified snapshot back onto this copy. Type the snapshot number to confirm.

## [Updates](/help/updates)

The administrator screen for this copy’s version and for applying waiting database changes after a deploy. It is not the first-time setup wizard.

## [System owner](/help/users-and-roles)

The first account created during setup. That person can grant administrators, recover another account, and set the active-user limit. Other people do not see this account in user lists or new assignment pickers. Decisions they already recorded stay visible on those requests.

## [Account recovery](/help/users-and-roles)

Owner-only actions on a person that invalidate a password and email a reset link, or remove that person’s authenticator, recovery codes, and passkeys. Both sign the person out everywhere.

## [Active-user limit](/help/users-and-roles)

The optional maximum number of accounts that can sign in. Inactive accounts do not count. The System owner is included. Off (unlimited) until the System owner sets a number.

## [Custom role](/help/users-and-roles)

A feature-based set of permissions created from Administration → Roles & permissions. Built-in roles stay protected. Custom roles can be cloned, edited, archived, restored, and assigned within the manager’s permission ceiling.

## [Role manager](/help/users-and-roles)

A person explicitly trusted by the System owner to manage custom roles. They cannot grant permissions above their own access, appoint another role manager, grant Administrator, or obtain System-owner powers.

## [Scheduler heartbeat](/help/operations)

The once-per-minute sign that scheduled work is running. Fresh means the last heartbeat is no more than five minutes old. Backup now is manual and does not create this proof.

