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

You cannot edit a published decision. Returned requests can be edited and resubmitted by the original submitter. When a returned request is resubmitted, everyone on the step that returned it decides again; approvals given before the return stay in the history but no longer count toward that step. Awaiting-information requests wait for that submitter’s reply, including files when asked.
