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

## Workflow builder

The canvas and list editor for a workflow template. Start and End are shown for orientation. The stored chain is still the ordered steps.

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

## Assignee resolver

How a step decides who acts: named users, a role, department members, the requester's manager, the department manager, a user picked on the form, or an external resolver.

## Group completion

How a step with more than one assignee finishes: first response, all assignees, or quorum N-of-M.

## Business calendar

Working days and holidays used when a step deadline is measured in business hours instead of elapsed hours.

## Custom outcome

An extra decision button, such as Approve with conditions. Each outcome maps to approve, reject, return, request information, or escalate.

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
