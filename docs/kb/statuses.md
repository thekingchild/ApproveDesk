# Request statuses

Status is the current state of the request. Workflow history is a separate, append-only list of actions.

| Status | Meaning | Who acts |
|---|---|---|
| Draft | Saved, not in a workflow yet | Owner edits and submits |
| Pending | Submitted, waiting to enter or resume a step | Assigned approvers |
| In review | An approval step is active | Current approvers or their delegates |
| Awaiting information | An approver asked a question | Requester replies |
| Returned | Sent back for changes | Requester revises and resubmits |
| Approved | Workflow finished with approval | Read-only |
| Rejected | Workflow finished with rejection | Read-only |
| Withdrawn | Owner cancelled the request | Read-only |

Completed in lists and dashboards usually means Approved or Rejected.

You cannot edit a published decision. Returned and awaiting-information requests are the ones that need the requester again.
