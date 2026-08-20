# Audit trail

Every decision is stored as a new workflow action. ApproveDesk does not update or delete those rows. The current step is derived from that history.

On a request you will see who acted, what they did, and when. Automatic steps and email-link approvals appear in the same list.

Comment edits write the previous text into an append-only revision list. Manual reminders are stored as new rows and are not rewritten.

## What you cannot change

- Recorded approve, reject, return, delegate, escalate, and information-request actions
- A published request-type version and its field definitions
- Files already attached to a submitted request, except as allowed by the current form rules while the requester still owns a draft or a returned request
- Comment revision history and reminder records

If a form needs to change, publish a new version. Old requests keep the version they were submitted against.

If a workflow needs to change, publish a new workflow version. In-flight requests finish on the version they already have. The original step IDs stay on the audit rows.

If a person leaves, deactivate the account. Their name stays on the actions they recorded.

Use analytics for trends. Use the request history when you need evidence of a single case.
