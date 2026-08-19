# Feature definitions

Short meanings for the words used on ApproveDesk screens. Open the linked guide when you need the full how-to.

## Request

A single case waiting for a decision. It has a form, optional files, comments, a status, and a workflow history. Requesters create requests. Approvers act on them.

See [Requests](requests.md).

## Request type

A named form, such as Leave Request or Purchase Request. The request type is what a requester chooses. It owns the fields people fill in and, from Phase 6A, the workflow that starts when they submit.

See [Request types](request-types.md).

## Workflow template

A named approval chain: the ordered steps, who acts, and what happens on timeout. Many templates can be active at once. A template does nothing until a request type is assigned to it, unless it is the default.

See [Workflow templates](workflow-templates.md).

## Workflow version

One frozen copy of a workflow template’s steps. New requests use the published version. Requests already in flight keep the version they started.

## Default template

The one fallback chain. It runs for General Request and for any request type that has no assigned template, or whose assigned template is inactive. There can be only one default.

## Workflow assignment

The link from a request type to a workflow template. New submissions of that form start that chain. Requests already in flight keep the chain they started with.

## Submission type

Document, Note, Memo, or Request. These are fields on the built-in General Request form. They are not a limit on how many templates you may create, and they do not reserve a workflow.

## Status

The current state of a request: Draft, Pending, In review, Awaiting information, Returned, Approved, Rejected, or Withdrawn.

See [Request statuses](statuses.md).

## Field key

The hidden machine name for a form field, generated from the label. Administrators do not type it. Search, analytics, and conditional rules use it internally.

## Audit trail

The append-only list of workflow actions on a request. ApproveDesk does not edit or delete those rows.

See [Audit trail](audit-trail.md).
