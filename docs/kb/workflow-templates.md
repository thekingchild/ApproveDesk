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

Changing an assignment or the default does not rewrite requests that already have a workflow. Those keep the template stored on their workflow instance.

## How to create a template

Open **Administration → Workflow templates** and add a template.

- Name it for the process, not for Document/Note/Memo/Request.
- Mark **Default template** only for the one fallback chain. Choosing a new default turns the previous default off.
- Leave the template **Active** if new work should be able to use it. An inactive template is ignored at submit time.
- Add steps from top to bottom.

You do not have to pick a submission type. The old Document / Note / Memo / Request checkboxes are optional labels only. They do not block a second template, and they do not choose the chain for new work.

## Step types

- Sequential: one assignee, then the next step.
- Parallel: every assignee must act.
- Quorum: N of M assignees must act.
- Conditional: route using request data and allow-listed comparisons.
- Automatic: the system records an approval when the rule matches.

Assignees can be a user, a role, or a department.

## Timeouts

Each step can have a deadline and a timeout action, such as escalate, auto-approve, or auto-reject. Overdue work shows in the workbench and on analytics.

## What not to do

Do not expect an edit of the steps to rewrite history. After a template has been used, its steps stay locked. You may still rename it, describe it, or deactivate it.

Do not create a second default. Only one default is active at a time.

Do not rely on Document / Note / Memo / Request to pick the chain. Assign the template on the request type instead.

Keep one default active so General Request and any unassigned form can still start a workflow.
