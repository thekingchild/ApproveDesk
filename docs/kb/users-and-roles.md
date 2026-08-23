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

A person can hold more than one role. Mixed roles add up — they receive every ability those roles include.

Only a super admin can assign the super-admin role. The last remaining super admin cannot be removed or deactivated. That prevents a lockout.

## Inactive accounts

Turn **Active** off to block sign-in and keep the person’s history. Do not reuse that email for a different person.

## Departments on users

Assigning a department is how department routing and filters find the person. It does not by itself grant department-head powers.

## Line manager

Set a line manager on the user record when workflow steps should assign the requester's manager. Search by name or email; the form does not list every user. The department manager on the department record is a separate assignee type.

## Phone number

Phone is optional. Enter it in international E.164 form, starting with `+` and the country code, for example `+2348012345678`. Spaces are stripped on save. Local numbers such as `08012345678` are rejected. This value is stored for later SMS and WhatsApp delivery; those channels are not sent from this screen.

## Import users

For large onboarding, open **Administration → Users → Import users**.

1. Download the CSV template. Columns are `name`, `email`, `department`, `manager_email`, `phone`, `roles`. Only name and email are required.
2. Fill it in Excel or a text editor and upload the `.csv` or `.xlsx`. Nothing is written yet.
3. Choose what to do if an email already exists: **Skip that row** (default) or **Update the existing account**.
4. Preview the import. Ready rows will be created; existing emails and problems are listed. Download the problem report if you need to fix the file.
5. Confirm. New accounts get a generated password — ask people to use **Forgot password** on the login screen to set their own.

Departments must already exist (the importer will not create them). A manager must be an existing user or appear earlier in the same file. Roles must be ones you are allowed to grant. Imported people are trusted immediately; they do not go through the self-registration email code. Passwords are never read from the spreadsheet.
