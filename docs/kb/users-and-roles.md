# Users and roles

Open **Administration → Users** to create accounts, change roles, attach a department, and deactivate people who should no longer sign in.

The account created during first-time setup is the protected **System owner**. That account is hidden from other people on this screen and cannot be edited, deactivated, or anonymized. Controls that are not shown to your account are outside your authority; ask the System owner to perform the action rather than trying to work around the restriction. Protected procedures are kept in [System owner controls](system-owner-controls.md), which is available only to that account.

## People with more access than you

You can still edit the name, phone, department, and line manager of someone whose roles go beyond what you could grant. Their **email address** and **account status** are read-only for you, and **Anonymize account** is not offered, because changing them could hand you control of a stronger account. Only the System owner can change these for administrators and for anyone holding permissions above yours.

## Roles

Built-in roles are protected defaults. People with **Manage custom roles** can open **Administration → Roles & permissions** and create, edit, archive, or restore custom roles within their own permission ceiling. A custom role combines selected feature permissions; a person with several roles receives the union of all of them.

A role manager can work only with custom roles and cannot grant permissions above their own access. Protected roles and protected permissions remain unavailable.

| Role | What it is for |
|---|---|
| Administrator | Built-in broad administration for users, departments, workflows, request types, branding, webhooks, and sensitive fields. |
| Department head | Department work, plus create and view submissions. |
| Approver | Act on assigned steps. |
| Submitter | Create, edit own drafts, and withdraw own requests. |
| Viewer | Read requests they are allowed to see. |
| Auditor | Read-only, time-bounded access after a grant on **Records**. Cannot approve or change settings. |

A person can hold more than one role. Mixed roles add up — they receive every ability those roles include.

When you grant roles on **Administration → Users**, every role you assign must stay within your own access. A non-owner cannot change their own roles from this screen. Saving name, email, department, phone, or other profile fields does not change the roles the person already holds, including roles you cannot grant.

Display-name requests from **Settings → Profile** appear on this screen. Approve or reject them; the current name stays until you approve. You cannot approve your own request.

Built-in roles can be cloned but not edited or deleted. Archive a custom role to stop new assignments without removing it from people who already hold it.

## Inactive accounts

Turn **Active** off to block sign-in and keep the person’s history. Do not reuse that email for a different person.

## Departments on users

Assigning a department is how department routing and filters find the person. It does not by itself grant department-head powers.

## Line manager

Set a line manager on the user record when workflow steps should assign the requester's manager. Search by name or email; the form does not list every user. The department manager on the department record is a separate assignee type.

## Phone number

Phone is optional. Enter it in international E.164 form, starting with `+` and the country code, for example `+2348012345678`. Spaces are stripped on save. Local numbers such as `08012345678` are rejected. This value is stored for later SMS and WhatsApp delivery; those channels are not sent from this screen.

## Import users

For large onboarding, open **Administration → Users → Import users**. A spreadsheet may include at most 5,000 data rows. Oversized or highly compressed workbooks are refused before preview.

1. Download the CSV template. Columns are `name`, `email`, `department`, `manager_email`, `phone`, `roles`. Only name and email are required.
2. Fill it in Excel or a text editor and upload the `.csv` or `.xlsx`. Nothing is written yet.
3. Choose what to do if an email already exists: **Skip that row** (default) or **Update the existing account**.
4. Preview the import. Ready rows will be created; existing emails and problems are listed. Download the problem report if you need to fix the file.
5. Confirm. New accounts get a generated password — ask people to use **Forgot password** on the login screen to set their own.

Departments must already exist (the importer will not create them). A manager must be an existing user or appear earlier in the same file. Roles must be ones you are allowed to grant. **Update the existing account** changes only people you could edit on the Users screen yourself: it needs the **Update users** permission, and administrators can be updated only by the System owner. Other existing rows are skipped. Imported people are trusted immediately; they do not go through the self-registration email code. Passwords are never read from the spreadsheet.

## Anonymize an account

When someone leaves and they have request or decision history, edit the user and choose **Anonymize account**. Their name becomes “Former user”, they cannot sign in, and the history stays on those requests. You cannot anonymize yourself or the System owner.

People download their own data from **Settings → Privacy**. See [Security and privacy](security-and-privacy.md).
