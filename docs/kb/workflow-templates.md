# Workflow templates

## Definition

A workflow template is a reusable approval chain. It names the steps a request will pass through, who must act at each step, and what happens if a step runs out of time.

A template is not a request. It is not a form. It is the path a submitted form will follow.

## When to use it

Create a template when a kind of work needs its own path: two-level leave approval, a purchase committee, a single-signer note, and so on. You may keep as many active templates as you need.

## How assignment works

New submissions do not pick a template. The **request type** (the form) does.

1. If the request type has an assigned, active template, that chain starts.
2. Otherwise the **default template** starts.
3. If there is no active default, the request cannot enter a workflow.

Changing an assignment or the default does not rewrite requests that already have a workflow. Those keep the **version** they started on.

## How to create a template

Open **Administration → Workflow templates** and add a template.

- Name it for the process, not for Document/Note/Memo/Request.
- Mark **Default template** only for the one fallback chain. Choosing a new default turns the previous default off.
- Leave the template **Active** if new work should be able to use it. An inactive template is ignored at submit time.
- Add steps from top to bottom.
- Open **Builder** for the canvas and list views of the same chain. Start and End are implied nodes.
- Add Approval, Condition, Automatic, or Notification nodes. Duplicate, delete, reorder, undo, and redo stay in this editing session.
- Run a read-only simulation with sample request data to see the route. It does not record decisions.
- Compare versions by their steps and names. Restore an older version as a new draft, then publish if it should apply to new work.
- Save records a draft if the chain changed. **Publish version** asks for confirmation, then freezes that graph for new requests and retires the previous published version. Publishing requires the **Publish workflows** permission; people who can only edit workflows can save drafts.
- Write a short change summary so later administrators can see why the chain changed.

You do not have to pick a submission type. The old Document / Note / Memo / Request checkboxes are optional labels only. They do not block a second template, and they do not choose the chain for new work.

## Step types

- Sequential: one assignee, then the next step.
- Parallel: every assignee must act.
- Quorum: N of M assignees must act.
- Conditional: route using request data and allow-listed comparisons.
- Automatic: the system records an approval when the rule matches.
- Notification: the request records that a notice is due and continues. Delivery uses the channels you have configured.

Assignees can be named people, a role, department members, the requester's line manager, the department manager, a person selected on the form, or another connected system. Manager and form-field assignees are resolved when the request starts.

For named people, roles, and departments, type at least two characters to search. Chosen records appear as chips. The page does not list everyone.

## Conditions

A Condition step checks one rule and sends the request down its **true** or **false** branch. A branch goes to a later step or completes the workflow.

- **Field** is a request field (`priority`, `type`, `title`, `department_id`, `submitter_id`, `due_date`), a form field written as `fields.amount`, or `metadata.amount` (which reads the form field `amount`). Any other field is refused when you save or publish, because no request could start on it.
- **Operator** includes equals, in a comma-separated list (for example `high, urgent`), greater than, at least, contains, and is present.
- In the builder, choose **Connect true branch** or **Connect false branch**, then click the target node. The inspector names the step each branch goes to.
- Branches follow the step you chose, not its number. Moving or duplicating steps keeps them pointing at the same step. If you delete a step that a branch points to, saving asks you to choose a new target; the branch is never quietly changed to "complete".
- A condition with more than one rule (from an earlier version or the API) keeps its other rules when you edit the first one.

Group completion is first response, all assignees, or quorum N-of-M. Sequential, parallel, and quorum step types still set that default.

Deadlines can use elapsed hours or business hours. Business hours use organization working days, office hours, and holidays from **Settings → Approval settings**. Elapsed hours count every hour on the clock.

Custom outcomes on a published version add extra decision buttons. Approve with conditions is always available and requires a comment. Every custom outcome maps to approve, reject, return, request information, or escalate. Editing a template on the **Workflow templates** list keeps settings made in the visual builder — custom outcomes and a step's completion rule (for example **All assignees**) — unless you change that step's type there. An outcome **key** uses 1 to 40 lowercase letters, numbers, or underscores (for example `approve_budget`); the label is what approvers see.

## Timeouts

Each step can have a deadline and a timeout action, such as escalate, auto-approve, or auto-reject. Overdue work shows in the workbench and on analytics.

## Versions

A template is the name of the process. A **version** is one frozen chain.

| Status | Meaning |
|---|---|
| Draft | Editable. New requests do not use it until you publish. |
| Published | Frozen. New requests of assigned types use this version. |
| Retired | Frozen. It was published before a later version took over. In-flight requests on it continue. |
| Archived | Frozen and hidden from new work. |

Each published version records who published it, when, and a short change summary.

## What not to do

Do not expect an edit of the steps to rewrite history. Published, retired, and archived versions cannot change their steps. Save a draft and publish it for new work instead.

Do not create a second default. Only one default is active at a time.

Do not rely on Document / Note / Memo / Request to pick the chain. Assign the template on the request type instead.

Keep one default active so General Request and any unassigned form can still start a workflow.

## What stays true after an upgrade

Requests that already started keep the **version** they started on. Publishing a later version, restoring an older draft, or changing selection rules does not rewrite that history. Simulation and Explain routing never add history rows. Checks run before publish so unreachable steps, missing assignees, and unsafe timeouts are refused.
