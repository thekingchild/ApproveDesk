# Users and roles

Open **Administration → Users** to create accounts, change roles, attach a department, and deactivate people who should no longer sign in.

## Roles

| Role | What it is for |
|---|---|
| Super admin | Every ability. Use for a small set of owners. |
| Admin | Users, departments, workflows, request types, branding, analytics, webhooks, and sensitive fields. |
| Department head | Department work, plus create and view submissions. |
| Approver | Act on assigned steps. |
| Submitter | Create, edit own drafts, and withdraw own requests. |
| Viewer | Read requests they are allowed to see. |

A person can hold more than one role. Permission checks use abilities, not the role name, so mixed roles add up.

Only a super admin can assign the super-admin role. The last remaining super admin cannot be removed or deactivated. That prevents a lockout.

## Inactive accounts

Turn **Active** off to block sign-in and keep the person’s history. Do not reuse that email for a different person.

## Departments on users

Assigning a department is how department routing and filters find the person. It does not by itself grant department-head powers.
