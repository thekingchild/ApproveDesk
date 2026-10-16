# Your account

Open Settings from your name at the bottom of the sidebar.

## Profile and appearance

Update your email from Profile. A new display name is sent for review: your current name stays in use until a different administrator approves the change. You can cancel a pending request from Profile.

Upload a JPEG, PNG, or WebP photo from Profile. ApproveDesk stores a re-encoded copy; SVG files are not accepted. The photo is visible to people who can see your account in the app.

Appearance switches light and dark theme for your session.

## Security

If you lose access to every second-factor method and its recovery codes, contact the System owner. Verify your identity using your organization's approved support process; do not send passwords or recovery codes in a message.

If an administrator turns on **Require a second factor**, you must confirm an authenticator or add a passkey before you can open the rest of ApproveDesk. You will stay on **Settings → Security** until you do.

## Notifications

Choose Immediate, Daily, Weekly, or In-app only. Immediate sends as events happen. Daily and weekly send a digest. A daily digest goes out once each calendar day, a weekly one once every seven days. In-app only keeps the bell and does not email you.

In-app only does not mute Microsoft Teams, Slack, WhatsApp, or Telegram. Those optional channels are organisation settings. If they are on and your account is mapped, they still notify you. See [Notification channels](notification-channels.md).

You can install ApproveDesk on this device and allow browser notifications. Drafts you type while offline stay on the device until you reconnect. Decisions still need a live connection.

Per-request follower preferences (comments vs status) live on the request, not here.

## Out of office

Open **Out of office** in Settings. Set a start, an end, and an optional fallback approver. Overlapping periods, a fallback who is also away, and circular fallbacks are rejected. While a schedule is active, current steps that allow delegation pass to the fallback. See [Collaboration](collaboration.md).

## API tokens

Personal tokens call the REST API as you. Create a token, copy it once, and store it outside ApproveDesk. Revoke tokens you no longer use. Tokens inherit your permissions.

## Devices

Open **Security**. Signed-in browsers appear under **Devices**. Sign out one you do not recognise, or **Sign out other devices** to keep only this browser. A sign-in from a new device or location sends you an email and an in-app notice.

## Privacy

Open **Privacy** to **Download my data**. The zip is your profile, requests you submitted, comments, followers, out-of-office periods, sign-in history, and decisions recorded in your name. Passwords, recovery codes, API tokens, and connector secrets are not included.

You can delete the account only when you have no request or decision history. History includes requests you submitted, decisions, delegations given to or by you, and comments. If you do, ask an administrator to anonymize the account so your name comes off while the history stays. See [Security and privacy](security-and-privacy.md).
