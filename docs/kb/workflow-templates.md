# Workflow templates

Open **Administration → Workflow templates** to define how a submitted request is routed.

A template is a named chain of steps. You can mark one template as the default and limit a template to selected submission types.

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

Do not edit a template in a way that you expect to rewrite requests already in flight. Running requests keep the steps they started with. New submissions pick up the template as it exists at submit time.

Keep a default template active so General Request and other types that rely on it can still start a workflow.
