# System settings

Open **Settings → System** to control outbound email, how long signed-in sessions last, and optional Google, Microsoft, or GitHub sign-in. This screen is for administrators who have been granted **Manage system settings**. Saving it asks you to confirm your password.

Do not paste credentials into Help, comments, or chat. Stored secrets are masked after save.

## Email delivery

Choose how ApproveDesk sends mail:

- **SMTP** for a conventional mail server (host, port, username, and password).
- **Amazon SES**, **Postmark**, or **Resend** for those providers’ API credentials.
- **Sendmail** to use the server’s existing sendmail command. The command itself cannot be edited here.

Set the From address and From name that people will see. Until you save an override, ApproveDesk keeps using the values already configured for the installation.

For each secret, choose **Keep**, **Replace**, or **Clear override**. Keep leaves the stored value unchanged. Replace writes the new value you typed. Clear override removes the saved value so the installation fallback is used again.

**Send test email** queues one message to the address you enter. Failures are shown as a generic delivery error; check Operations or the application log for detail. Repeated tests are limited.

After you save mail settings, restart long-lived queue workers so they load the new configuration.

## Browser sessions

**Idle lifetime** is how many minutes a signed-in session can sit unused before it expires (5–1440 minutes).

**Expire sessions when the browser closes** ends the session when the browser window is closed. While this is on, **Remember me** is hidden on Login, and social sign-in does not keep a long-lived device cookie.

These settings apply to newly issued sessions. They do not weaken browser cookie protections.

## Social sign-in

Enable Google, Microsoft, and/or GitHub independently. Each provider needs a client ID and client secret from that provider, plus the callback URL shown on the card. Microsoft also accepts a tenant (commonly `common` or `organizations`).

Password sign-in remains available. Existing accounts can link a matching verified email. New accounts still follow registration, domain, and active-user capacity rules.

Turn a provider off to hide its Login button even if environment credentials still exist.

## What not to do

- Do not store secrets in screenshots, Help, or tickets.
- Do not enable Log delivery on a live installation; it is a local diagnostic option only.
- Do not expect queue workers to pick up mail changes until they are restarted.
- Do not treat social sign-in as a replacement for the domain allow-list or two-factor authentication.
