# Users and roles

Open **Administration → Users** to create accounts, change roles, attach a department, and deactivate people who should no longer sign in.

The account created during first-time setup is the **System owner**. That person is hidden from other people on this screen and cannot be edited, deactivated, or anonymized. Only the System owner can grant the Administrator role or recover another person’s password or second factor.

## Roles

Built-in roles are protected defaults. The System owner can open **Administration → Roles & permissions**, clone a built-in role, and create, edit, archive, or restore custom roles. A custom role combines the selected feature permissions; a person with several roles receives the union of all of them.

The owner may appoint a role manager. A role manager can work only with custom roles and cannot grant permissions above their own access. They cannot grant Administrator, role-manager authority, account recovery, active-user capacity, System-owner authority, or the ability to add analytics and custom code.

| Role | What it is for |
|---|---|
| Administrator | Users, departments, workflows, request types, branding, webhooks, and sensitive fields. Cannot grant another Administrator, recover passwords, or add analytics and custom code. |
| Department head | Department work, plus create and view submissions. |
| Approver | Act on assigned steps. |
| Submitter | Create, edit own drafts, and withdraw own requests. |
| Viewer | Read requests they are allowed to see. |
| Auditor | Read-only, time-bounded access after a grant on **Records**. Cannot approve or change settings. |

A person can hold more than one role. Mixed roles add up — they receive every ability those roles include.

Only the System owner can grant or remove the Administrator role. Built-in roles can be cloned but not edited or deleted. Archive a custom role to stop new assignments without removing it from people who already hold it.

## Account recovery

If someone loses their authenticator, passkeys, and recovery codes, the System owner opens that person and uses **Account recovery**.

- **Invalidate password and email reset link** signs them out everywhere and sends a one-use reset email. The owner never sees their new password.
- **Remove authenticator, recovery codes, and passkeys** signs them out everywhere and lets them sign in with their password to add a new second factor.

These actions do not change roles, request history, or whether the account is active.

## Active-user capacity

The System owner can set a maximum number of **active** accounts on this same screen, or leave it unlimited. The System owner counts as one active user. Inactive accounts do not count. Existing people can still sign in when the limit is full; new active accounts are refused until someone is deactivated.

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

## Anonymize an account

When someone leaves and they have request or decision history, edit the user and choose **Anonymize account**. Their name becomes “Former user”, they cannot sign in, and the history stays on those requests. You cannot anonymize yourself or the System owner.

People download their own data from **Settings → Privacy**. See [Security and privacy](security-and-privacy.md).
