# Approval settings

Open **Settings → Approval settings** to control approval IDs, separation of duties, signed approval links, deadline calendars, and reminder quiet hours. Use these controls when organization-wide approval timing or identity requirements change.

## Approval ID format

The **System owner** can choose the format used for new approval IDs. Other administrators who can manage approval settings can still manage link and calendar options, but they cannot see or change this owner-only card.

Choose:

- a letters-and-numbers **prefix** of up to 12 characters;
- a hyphen, slash, or no separator;
- a four-digit year, two-digit year, or no year;
- a two-digit month, three-letter month, or no month;
- between 4 and 12 number digits; and
- **Sequential** or **Random** generation.

The preview shows the next ID shape before you use it. The original default remains `SUB-YYYY-00001`, so updating an existing installation does not silently change its numbering.

Sequential numbers restart when an included year or month changes. If neither date part is included, the sequence is continuous. Returning to an earlier format or date period resumes its protected counter. Changing the number of digits starts a separate counter. Random IDs retry collisions and always use the selected number of digits. If a sequential format runs out of numbers of that length, ApproveDesk stops issuing IDs rather than making them longer.

A format change affects only approvals created after the save. Existing IDs, links, reports, and audit history remain unchanged. Avoid changing the format during a high-volume intake window unless everyone who records IDs has been told.

Printed approval reports and downloaded PDFs display the supplied image stamp for the approval's current status in the report heading. The stamp is evidence of the status when the report was generated; it does not change the stored workflow history.

## Separation of duties

**Requesters cannot approve their own requests** is on by default. While it is on:

- A requester is never one of the approvers on their own request, even when a step names them, includes their role or department, or resolves to them as department manager or as the person picked on the form.
- Nobody can delegate a step to the requester, and a delegation granted to the requester earlier cannot be used.
- On an **All** or **Quorum** step, the requester's place is not counted, so the remaining approvers decide.
- The requester sees a short note on the request page explaining that another approver must decide. They can still comment and reply to information requests.

Use it to keep the person who asks for something from also signing it off, which most audit and compliance policies require.

If a step would be approved **only** by the requester, for example a department manager's own request on a **Department manager** step, the request cannot move to that step. The person submitting, or the approver whose decision would move it there, sees a message naming the step. Change who approves that step in the workflow template (for example, add a second approver or route to the requester's manager), then try again.

Switching it off lets requesters approve their own requests again wherever a step includes them. The change applies immediately to requests in progress.

## Approval email links

Set how long a signed approval link stays valid, from 1 to 720 hours. The default is 72 hours. Shorter is safer; longer is more convenient for people who batch email.

Changing the lifetime applies to newly sent links. A link already in someone's inbox keeps the expiry it was issued with.

Turn on **Require sign-in and a second factor for approval links** when the named approver must prove their ApproveDesk identity before deciding. The signed link and its expiry still apply, and the named approver must sign in and verify an authenticator, recovery code, or passkey in that browser session. A different signed-in account is refused without showing the request.

The setting affects every link still within its original expiry, including messages already sent. The person enabling it must already have a second factor and verify it in the current session. People without a factor are directed to **Settings → Security**.

This option covers messages that open the ApproveDesk approval page. Direct approval buttons inside Teams and Slack use their own signed action. Keep `{{approval_url}}` in action-required email copy.

## Business calendar

Set working days, optional office hours, and holiday dates. Workflow steps using **Business hours** count only working days that are not holidays and only the office-hour window when one is set.

Leave both office-time fields empty to count every hour of a working day. Do not enter 00:00–23:59 to mean all day; that is 23 hours and 59 minutes.

Times use the installation timezone shown on this screen. Changes immediately affect in-progress Business-hours deadlines, overdue badges, automatic SLA actions, and estimated due times. Steps using **Elapsed hours** ignore this calendar. Approval-link lifetime is also a separate elapsed clock.

## Reminder quiet hours

Set an optional start and end time. A manual reminder recorded inside the window is delivered when the window ends. Overnight ranges such as 22:00–07:00 are allowed. Leave both fields empty to deliver reminders immediately.

Quiet hours do not change office hours, workflow deadlines, or approval-link expiry.

## What not to do

- Do not switch off **Requesters cannot approve their own requests** unless your policy truly allows self-approval; decisions made while it is off stay in the audit trail.
- Do not extend link lifetime without considering the extra exposure window.
- Do not enable second-factor enforcement before affected approvers can enroll.
- Do not enter an almost-all-day office window when an empty window means the full working day.
- Do not treat quiet hours as a change to workflow due dates.
