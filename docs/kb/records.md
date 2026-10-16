# Records

Records is the administrator screen for evidence, auditor access, legal holds, and how long finished work is kept.

Open **Administration → Records**.

## When to use it

Use this screen when an auditor needs time-bounded read access, a request must not be deleted, a finished request is ready for disposal, or you need to check an evidence pack.

## How the live screens work

### Auditor access

Assign the Auditor role on **Users**, then grant a start, an end, and a reason here. While the grant is active the auditor can open requests and download evidence packs. They cannot approve, comment, or manage users. **Revoke** ends the grant immediately. Expired grants stop access on their own.

Every evidence-pack download is written to an export log.

### Legal holds

Enter a request reference and a reason. A hold blocks automatic deletion. **Release** ends the hold.

### Retention and disposition

Set how many days drafts and finished requests are kept. Drafts older than the draft window are removed unless they have a hold. Finished requests (approved, rejected, or withdrawn) are removed only after that window **and** an approved disposition review.

Open a disposition review from a request reference, then approve it when disposal is allowed. Each review is decided once. While **Requesters cannot approve their own requests** is on (Approval settings), a different person must decide a disposition you opened. Auditors' access ends when their grant ends, even if they followed requests while it was active.

### Verify a pack

Upload a downloaded evidence pack. Intact packs are confirmed. Altered files or a broken signature are refused.

## What not to do

- Do not grant auditor access without an end date you intend to honour.
- Do not release a legal hold to make retention run if the matter is still open.
- Do not treat a failed pack check as a problem with the original request — download a fresh pack from ApproveDesk.
