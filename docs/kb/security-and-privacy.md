# Security and privacy

Security and privacy settings control how sensitive answers are treated, how file downloads are stamped, how often people may retry sign-in or exports, and how an account is closed without erasing request history.

Open **Settings → Security & privacy**. People use **Settings → Privacy** to download their own data and **Settings → Security** to sign out devices.

## When to use it

Use these screens when a form collects confidential answers, when downloads must show who took the file, when you need to slow down guessing or bulk exports, or when someone leaves and their name should come off the account.

## How the live screens work

### Sensitive fields

On a request type, mark a field **Sensitive**. People without the sensitive-fields ability do not see the value in search, reports, list columns, or request detail. The requester still sees their own answers. Administrators with that ability can see the value. Each time someone other than the requester opens a sensitive value, ApproveDesk writes a row on **Security & privacy**.

Exports and evidence packs replace a sensitive value with `[redacted]` when the person downloading is not allowed to see it.

### Download stamp

Turn on **Stamp file downloads**. Image downloads show who downloaded them. Other files arrive as a zip with a notice. Previews are not stamped.

### Devices and unusual sign-in

**Settings → Security** lists devices signed in as you. **Sign out** ends one session. **Sign out other devices** keeps only this browser. A sign-in from a new device or location sends an email and an in-app notice.

### Rate limits

Set how many attempts per minute are allowed for sign-in, the API, approval links, exports, incoming webhooks, and integrations. Lower numbers stop guessing and bulk downloads sooner.

### Download my data

Anyone can open **Settings → Privacy** and choose **Download my data**. The zip has profile, submitted requests, comments, followers, out-of-office periods, sign-in history, and decisions recorded in their name. Passwords, recovery codes, API tokens, and connector secrets are not included.

### Anonymize an account

On **Administration → Users**, edit the person and choose **Anonymize account**. Their name becomes “Former user”, the email is replaced, and they cannot sign in. Decisions they already recorded stay on those requests. You cannot anonymize yourself or the last active super administrator.

People can delete their own account only when they have no request or decision history. Otherwise they download their data and ask an administrator to anonymize.

## What not to do

- Do not mark everyday fields sensitive unless the answer should stay hidden from ordinary approvers.
- Do not treat a download stamp as a substitute for signed links. Anyone who already has the file can still copy it.
- Do not raise rate limits to hide a compromised account — sign that person out and change their password.
- Do not delete a person who has approved or submitted work. Anonymize them so the history still names a former user.
