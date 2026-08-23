# Feature definitions

Short meanings for the words used on ApproveDesk screens. Open the linked guide when you need the full how-to.

## Public website

The marketing page visitors see at the site address before they sign in. Guests see **Log in** and, when self-registration is allowed, **Sign up**. If you are already signed in, that button becomes **Dashboard**.

See [Getting around](getting-around.md).

## Request

A single case waiting for a decision. It has a form, optional files, comments, a status, and a workflow history. Requesters create requests. Approvers act on them.

See [Requests](requests.md).

## Request type

A named form, such as Leave Request or Purchase Request. The request type is what a requester chooses. It owns the fields people fill in and the approval chain that starts when they submit.

See [Request types](request-types.md).

## Workflow template

A named approval chain: the ordered steps, who acts, and what happens on timeout. Many templates can be active at once. A template does nothing until a request type is assigned to it, unless it is the default.

See [Workflow templates](workflow-templates.md).

## Workflow builder

The canvas and list editor for a workflow template. Start and End are shown for orientation.

## Workflow version

One frozen copy of a workflow template’s steps. New requests use the published version. Requests already in flight keep the version they started, including after an upgrade.

## Graph validation

The checks that run before a workflow version is published: unreachable steps, cycles, missing assignees, impossible quorum, invalid branches, and unsafe timeouts.

## Default template

The one fallback chain. It runs for General Request and for any request type that has no assigned template, or whose assigned template is inactive. There can be only one default.

## Workflow assignment

The link from a request type to a workflow template. New submissions of that form start that chain unless a selection rule matches first. Requests already in flight keep the chain they started with.

## Selection rule

An ordered rule on a request type that chooses a workflow from form values. Priority 1 is evaluated first. The first match wins, then the assigned template, then the default.

## Assignee picker

The search field on a workflow step that finds users, roles, or departments without listing everyone. Selected people appear as chips.

## Assignee resolver

How a step decides who acts: named people, a role, department members, the requester's manager, the department manager, a person picked on the form, or another connected system.

## Group completion

How a step with more than one assignee finishes: first response, all assignees, or quorum N-of-M.

## Notification channel

A delivery path for events such as “action required”. In-app and email are always on. Teams, Slack, WhatsApp, and Telegram are optional administrator channels. Browser push is opted in per device.

See [Notification channels](notification-channels.md).

## Email template

Organisation-owned subject and body for a notification kind. Generic copy is the fallback until an administrator saves an override. Action-required templates must keep `{{approval_url}}`.

## WhatsApp template mapping

A link from an ApproveDesk notification kind to an approved WhatsApp Business template name, language, and placeholders. Unmapped or unapproved templates skip WhatsApp; ApproveDesk does not send free-form WhatsApp.

## Telegram bot

An optional chat bot. People link their Telegram account; ApproveDesk sends a message and a review link. Approvals still happen in ApproveDesk, not inside the chat.

## Branding

The organisation identity administrators set under Settings → Branding: application name, meta description, logo, favicon, login tagline, accent colour, and login background. The logo and name appear after sign-in; the background is the public login screen.

See [Organization settings](organization-settings.md).

## Business calendar

Working days, office hours, and holidays used when a step deadline is measured in business hours instead of elapsed hours. Empty office hours mean every hour of a working day counts; elapsed hours ignore the calendar.

## Custom outcome

An extra decision button, such as Approve with conditions. Each outcome maps to approve, reject, return, request information, or escalate.

## Submission type

Document, Note, Memo, or Request. These are fields on the built-in General Request form. They are not a limit on how many templates you may create, and they do not reserve a workflow.

## Status

The current state of a request: Draft, Pending, In review, Awaiting information, Returned, Approved, Rejected, or Withdrawn.

See [Request statuses](statuses.md).

## Field label

The name you type for a form field. ApproveDesk keeps that name attached to visibility rules and reports even if you later rename the field. You do not invent a technical name.

## Follower

Someone granted explicit view access to a request so they can open it and receive comment or status notifications. Followers are not approvers unless the workflow also assigns them. They can unfollow themselves.

See [Collaboration](collaboration.md).

## Mention

A named person in a comment. A mention notifies them and adds them as a follower so they can open the request.

## Reminder

A manual nudge to the current approver. It is recorded, rate limited, and delayed when reminder quiet hours are in effect.

## Out of office

A dated absence on your account, with an optional fallback approver. While it is active, current steps that allow delegation pass to the fallback.

## Expected next step

The next approval step the current request is expected to reach after the people on the current step decide. It is a preview, not a change to the bound workflow version.

## Estimated due time

The clock for the current step’s timeout, using elapsed hours or the business calendar (working days, office hours, holidays). It updates if the business calendar changes.

## Audit trail

The history of decisions on a request. ApproveDesk does not change or remove those entries.

See [Audit trail](audit-trail.md).

## Confirmation code (OTP)

A 6-digit number emailed to someone who self-registers when **Require email confirmation** is on. It expires in 10 minutes and can be used once. Sign-in is blocked until they enter it.

See [Organization settings](organization-settings.md).

## Domain allow-list

The list of email domains permitted to self-register, set on Branding. Empty means any provider. It does not apply to accounts an administrator creates or imports.

See [Organization settings](organization-settings.md).

## User import

A spreadsheet upload on **Administration → Users** that creates many accounts at once after a dry-run preview. Passwords are generated, not imported.

See [Users and roles](users-and-roles.md).

## Integration hub

The administrator screen for connectors and post-approval actions (upload a file, send mail, update an external record, or call a signed webhook). Failed deliveries can be replayed.

See [Integration hub](integrations.md).

## Connector

One configured destination in the integration hub, such as Slack or generic HTTP. Credentials are encrypted. A disabled connector skips.
