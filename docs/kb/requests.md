# Requests

A request is one approval case: a form, optional files, comments, and a workflow history.

## Start a request

Choose **New submission**. If more than one request type is published, pick the form that matches the work. General Request is the built-in form with title, type, body, priority, department, and due date.

Custom forms show the fields an administrator published. Required fields, help text, and file types come from that published version.

## Drafts and autosave

You can leave a request as a draft. The form saves while you work. Status text shows Saving, Saved at, or a retry prompt if the save failed.

If you go offline, the banner says the form is not being saved to ApproveDesk. This device still keeps a local copy. When you come back, if that copy differs from the saved draft, choose **Keep this device** or **Keep saved draft**. Approvals and submit still need a live connection.

Open drafts from **My submissions** using the Drafts view.

## Files and comments

Attachments are private. People with access to the request download them through a short-lived signed link. Comments stay on the request and are part of the record. You can reply to a top-level comment, mention a person (they are added as a follower), and edit your own comment for 15 minutes. Earlier wording is kept in the edit history.

Followers can open the request even if they are not on the workflow. Unfollow yourself from the Followers card. Reminders go to the current approver from the same page.

After you submit, the workflow card shows the current step, the expected next step, and an estimated due time when the step has a timeout. See [Collaboration](collaboration.md).

## Submit, withdraw, revise

Submit when the form is complete. That starts the workflow assigned to the request type.

Withdraw cancels an in-flight request that you own. Use it when the work is no longer needed.

If an approver returns the request, status becomes Returned. Edit the form, answer the note, and submit again. The workflow continues from the return; it does not rewrite earlier decisions.

## Lists and saved views

**My submissions** has views for Drafts, In progress, Needs my response, Completed, and Withdrawn. Administrators who can see all submissions get an organization list with filters, reportable columns, and named saved views.

Sensitive form fields stay hidden from people who are not allowed to see them, including in search and list columns.

## Print or save an approval report

An approval report is a document-ready record of one request. Use it when you need a presentable copy for a meeting, filing, an audit response, or an email attachment.

Open the request and choose **Approval report**. The preview shows the organization identity, request summary, request details, visible form fields, workflow progress, recorded decisions and comments, and an attachment list. The request's current status appears as a colored stamp at the upper-right so it is easy to identify without covering report content. Choose **Print** for the browser print dialog, or **Download PDF** for a file you can store or send. Times use the installation timezone shown in the report footer.

The report follows the same access rules as the request. A sensitive field you cannot open on the request is not revealed in the preview or PDF. Opening an authorized sensitive value is recorded. Attachment names are listed, but the report does not place the attachment contents inside the PDF.

Do not treat the report as a handwritten or cryptographic signature. It is a formatted account of decisions already recorded in the workflow history. Printing or downloading it does not change the request, add a decision, or rewrite an earlier action.
