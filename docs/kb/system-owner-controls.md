# System owner controls

The **System owner** is the singleton account established during first-time setup. These controls are protected because they can recover accounts, change the highest delegated authority, run code in signed-in sessions, or publish organization-wide content. Do not share this guide or delegate an owner session.

## Account recovery

Use account recovery only after verifying the person's identity outside ApproveDesk.

1. Open **Administration → Users** and edit the affected person.
2. Under **Account recovery**, choose the least disruptive action.
3. Confirm your password and current second factor when asked.

- **Invalidate password and email reset link** signs the person out everywhere and sends a one-use reset email. You never see the new password.
- **Remove authenticator, recovery codes, and passkeys** signs the person out everywhere and lets them sign in with their password to enroll a new factor.

Recovery does not change roles, request history, or active status. Never use it merely to bypass a user's second factor.

## Administrator grants and active-user capacity

Only the System owner can grant or remove the protected Administrator role. Before granting it, confirm that the person needs the full built-in administrative set; prefer a narrower custom role when possible.

On **Administration → Users**, set the maximum number of active accounts or leave it unlimited. The System owner counts as one active account. Inactive accounts do not count, and existing active people can still sign in when the limit is reached.

## Publish an announcement

Open **Administration → Announcements**.

1. Enter a specific title and a message without secrets or unnecessary personal information.
2. Choose all active users or selected roles.
3. Choose Sticky, Acknowledge, or Show once.
4. Select **Save announcement** to create a draft.
5. Review the audience and display mode, then select **Publish**.

Editing a published announcement republishes it. Archive it to end display. A receipt means shown or acknowledged, not email delivery.

## Manage the theme catalogue

Open **Administration → Application themes**. Built-in palettes can be enabled or disabled but not edited or archived. Custom palettes use fixed six-digit hexadecimal colors and must meet at least 4.5:1 contrast in light and dark modes.

Use **Save custom theme** to add a palette, **Make default** to choose the organization fallback, and **Archive** to retire a custom palette. Keep at least one enabled theme and choose a new default before disabling or archiving the current one.

## Analytics and custom code

Open **Settings → Branding → Advanced: analytics and custom code**. This content can run on Login, the signed-in application, and the public landing page.

- Add only code from a reviewed provider or a source you control.
- Never put passwords, API secrets, private keys, or user-specific data in the field.
- Confirm your password and second factor before saving.
- Remove the code immediately if it causes an unsafe redirect, captures form data, or breaks navigation.

People with ordinary Branding access can manage identity and appearance but cannot view or change this field.

## What not to do

- Do not assign Administrator when a feature-based custom role is sufficient.
- Do not recover an account without independent identity verification.
- Do not use announcements as approval evidence.
- Do not weaken palette contrast to match artwork.
- Do not paste unreviewed scripts or secrets into custom code.
