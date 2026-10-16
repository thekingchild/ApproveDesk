# Collaboration

Collaboration is how people stay on a request without taking over the decision. Followers watch it. Comments and mentions record discussion. Reminders poke the current approver. Out of office names a fallback while you are away. Expected next step and estimated due time show the requester where the work is heading.

## When to use it

Use followers when someone must see the request but is not on the workflow. Use comments and mentions for questions that are not a formal information request. Use a reminder when an approver is silent. Set out of office before you leave. Read expected next step when you submitted the request and want to know what happens after the current approver.

## Followers

Open a request. The Followers card lists people who can open it because they were added, not only because they submitted it or sit on the current step.

Follow adds you. Following yourself keeps you notified, but it does not give you access by itself: if you could open the request only through an auditor grant or a delegation, you lose access when that ends. People added by the requester, an approver, or a mention keep access while they are followers. Read-only auditors cannot follow requests. Unfollow removes you. Only you can remove your own follow, unless you were added by someone who can still manage followers (the requester, a current approver, or an administrator who can view all requests).

Adding a person grants them view access. That is the explicit permission. Do not add people who should not read the form or files.

While you follow, you can turn off comment notifications or status notifications. Mentions still notify the person named in the comment.

## Comments, mentions, replies, and edits

Comments sit on the request. They are part of the record.

Type a comment, then search by name to mention someone. A mention notifies that person and adds them as a follower so they can open the request. You can mention someone who cannot already open the request only if you are allowed to add followers (the requester, a current approver, or an administrator); otherwise ask one of them to add the person.

Reply starts a thread under a top-level comment. You cannot reply to a reply; stay one level deep.

The author can edit for 15 minutes. After that the text is locked. Each edit writes the previous text into an append-only history. Do not treat comments as a place to rewrite a decision; workflow actions remain the audit of approve, reject, return, and the rest.

## Reminders

Remind approver notifies the people who currently hold the step, including an active delegate. You cannot remind yourself.

The organization can set quiet hours on Approval settings. A reminder sent inside that window is recorded immediately and delivered when the window ends. If the step has moved on by then, the held chat reminder is dropped. Overnight ranges such as 22:00–07:00 are allowed. Empty quiet hours send immediately.

Reminders are rate limited: one to the same person on the same request each hour, and a small daily cap per sender on that request. The cap counts how many times you press **Send reminder**, not how many approvers each press reaches, so a step with many approvers can still be reminded. If some approvers were reminded within the last hour, they are left out and the confirmation names them; the others still get the reminder. You see an error only when everyone on the step was reminded too recently. Reminder rows are not edited or deleted.

## Out of office

Open **Settings → Out of office**. Set a start, an end, an optional fallback approver, and an optional note.

While the schedule is active and the current step allows delegation, ApproveDesk hands that step to the fallback, the same way a manual delegation would. The fallback can decide. You cannot decide on that step until the grant ends — unless you come back early: deleting or shortening the schedule hands those steps back to you, and the request page offers **Take back** on any step you delegated. If the person you delegated to is deactivated, the step returns to you automatically.

Conflicts are rejected: overlapping periods on your own calendar, a fallback who is also away, a fallback who already falls back to you, or delegating a request to someone who is away during that window.

A future start on the request’s Delegate dialog keeps your authority until that time, then passes it.

## Expected next step and due time

Requesters and other viewers see the current step, who is assigned, the expected next step, and an estimated due time when the step has a timeout.

The due time uses the step’s calendar: elapsed hours count every hour; business hours use working days, office hours, and holidays. Changing office hours immediately changes in-flight business due times. This estimate is not a promise and does not replace the workflow version bound to the request.

## What not to do

Do not add followers to leak a request. Do not mention people who should not see the files. Do not use a reminder to bypass a returned or information-request pause. Do not edit a comment after the window by asking an administrator to change the row. Do not set overnight office hours; that control is only on reminder quiet hours.
