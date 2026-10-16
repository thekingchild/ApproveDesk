# Approvals

The approval workbench is the queue of work assigned to you, including work delegated to you.

## Views

- Needs my decision: you are the current actor.
- Due soon: a deadline is close.
- Overdue: the step deadline has passed. Overdue uses the step’s deadline calendar (elapsed or business, including office hours when set).
- Waiting on others: you are involved, but someone else must act.
- Completed: finished work you can still open.

Filter by status, deadline, priority, request type, department, requester, and delegation.

## Preview and decide

Open a card for the full request, or use the preview drawer to read the summary without leaving the queue. The sticky decision bar on the request holds Approve, Approve with conditions, Reject, and related actions. A template may add extra outcomes; each one still maps to approve, reject, return, request information, or escalate.

Decision buttons disable while you are offline. Approvals still need a live connection — a saved draft or an offline copy cannot record a decision.

Reject, return, escalate, request information, and approve with conditions need a comment. Approval can be confirmed from the workbench for eligible items.

Keyboard shortcuts move to the next or previous request and open the approve or reject dialog. Destructive actions still need confirmation.

For a document-ready copy, choose **Approval report** on the request. The preview and downloadable PDF include the request information you are allowed to see, workflow steps, recorded decisions, decision comments, the attachment list, and a compact image stamp showing the current Draft, Pending, In review, Approved, Rejected, Returned, Awaiting information, or Withdrawn status. The stamp records the status displayed when the copy was generated; workflow history remains the authority. See [Requests](requests.md) for printing, sensitive-field, and signature guidance.

## After a return

If someone on a step returns the request for revision, approvals already given on that step stop counting once the requester resubmits. Everyone on that step reviews the revised request and decides again; the earlier approvals remain visible in the request history.

## Batch approve

You can approve several requests at once only when they are the same workflow step and you are the current actor. Reject, return, escalate, and information request stay one-at-a-time.

## Delegate, escalate, ask for information

Delegate hands your current step to another active user for a limited time. You can set a future start; until then you still decide. Escalate moves the work to the next step when policy allows. Request information pauses the step until the requester replies.

Delegation is refused if the other person is out of office during that window, already holds a seat, is the requester (when separation of duties is on), or the window overlaps a grant you already made.

## Your own requests

When **Requesters cannot approve their own requests** is on in Approval settings, your own requests never appear in your approval queue, even if a step would otherwise include you. The request page tells you another approver must decide. You can still comment.

A requester or watcher can send a reminder to the current approver. Quiet hours and rate limits apply; see [Collaboration](collaboration.md).

## Email links

If your organization sends approval emails, the link opens a confirmation page and records the decision. Links expire after the lifetime set in Organization settings (1 to 720 hours). Stale links, reused links, tampered links, and steps that have already moved on are refused. Teams and Slack **Approve** buttons use the same stale-step rules.
