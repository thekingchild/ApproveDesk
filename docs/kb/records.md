# Records

Records is the administrator screen for evidence, auditor access, legal holds, and how long finished work is kept.

Open **Administration → Records**.

## When to use it

Use this screen when an auditor needs time-bounded read access, a request must not be retired, a finished request is ready for disposal, or you need to check an evidence pack.

## How the live screens work

### Auditor access

Assign the Auditor role on **Users**, then grant a start, an end, and a reason here. Start and end are read and listed in your own time zone, shown under the fields. While the grant is active the auditor sees every request under **All submissions** and on the dashboard, can open requests, and can download evidence packs. They cannot approve, comment, or manage users. **Revoke** ends the grant immediately. Expired grants stop access on their own.

Every evidence-pack download is written to an export log.

### Legal holds

Enter a request reference and a reason. A hold stops retention from retiring the request. **Release** ends the hold. A request can have one active hold at a time. If someone else already released a hold, **Release** tells you so.

### Retention and disposition

Set how many days drafts and finished requests are kept (drafts 1 to 3,650 days, default 90; finished requests 1 to 7,300 days, default 2,555, about seven years). Each change is recorded in the activity log with who made it and the old and new values. Drafts that nobody has edited for longer than the draft window are retired unless they have a hold; editing a draft starts its window again. Finished requests (approved, rejected, or withdrawn) are retired only after that window **and** an approved disposition review. Retention runs once a day; a very large backlog is worked through over several days.

**Retired** means the request disappears from lists, search, dashboards, and its own page. It is **not erased**: ApproveDesk keeps the request, its workflow history, comments, and files stored as audit evidence, and its reference number is never reused. ApproveDesk never permanently deletes a request on its own.

Open a disposition review from a request reference, then approve it when disposal is allowed. A review that someone already decided shows a message instead of an error. Disposition is only for finished requests: a draft, or a request still in review, returned, or waiting for information, is refused with a message. Each review is decided once. While **Requesters cannot approve their own requests** is on (Approval settings), a different person must decide a disposition you opened. Auditors' access ends when their grant ends, even if they followed requests while it was active.

### Verify a pack

Upload a downloaded evidence pack (the `.zip` file). An intact pack shows **Verified**. Altered files, extra files added to the zip, a changed manifest (for example its date or request number), or a broken signature are refused. Packs downloaded before signed manifests were introduced show **Unsigned (legacy)**: their files still match, but their date and request number are not signed, so download a fresh pack when you need a fully verified copy.

## What not to do

- Do not grant auditor access without an end date you intend to honour.
- Do not release a legal hold to make retention run if the matter is still open.
- Do not expect retention to free storage or erase personal data: retired requests are kept as evidence. For a person's data, see [Security and privacy](/help/security-and-privacy).
- Do not treat a failed pack check as a problem with the original request — download a fresh pack from ApproveDesk.
