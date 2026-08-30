# Announcements

An **announcement** is an in-app message that the System owner publishes to all active users or to people who hold selected roles. It appears at the top of the signed-in **Dashboard**. Announcements are separate from approval notifications: they are not emailed or sent to Teams, Slack, WhatsApp, or Telegram.

## When to use an announcement

Use announcements for information that people should see while working in ApproveDesk, such as a policy change, planned maintenance, a deadline, or an important organization notice.

Choose the display mode that matches the message:

- **Sticky** stays on the Dashboard until the System owner archives it. A user cannot dismiss it.
- **Acknowledge** stays visible on the Dashboard for each eligible person until that person selects **Mark as read**.
- **Show once** appears on the first eligible Dashboard load and is then hidden for that person. Opening another page does not consume it.

Use **All active users** when the message applies to everyone. Use **Selected roles** when it applies only to one or more roles. A person with any selected role is included.

## Create and publish

Only the System owner can open **Administration → Announcements** or change announcements.

1. Enter a short, specific title.
2. Write the message in complete sentences. Select text and use the buttons for **bold**, *italic*, underline, bulleted or numbered lists, and links. Links must use `http`, `https`, `mailto`, a page path, or an in-page anchor. Formatting is stored as safe HTML; scripts and unsafe markup are removed when you save.
3. Choose **All active users** or **Selected roles**. When selecting roles, choose at least one.
4. Choose Sticky, Acknowledge, or Show once.
5. Select **Save announcement**. Saving creates a draft; it does not show the message.
6. Review the audience and display-mode summary, then select **Publish**.

The list shows Draft, Published, or Archived status and how many user receipts have been recorded. A receipt means the announcement was shown or acknowledged; it is not an email-delivery report.

On Dashboard, a long message starts as a short excerpt. Select **Show more** to expand it and **Show less** to make it compact again. Expanding or collapsing does not mark an Acknowledge message as read.

## Correct, end, or republish a message

Select **Edit** to correct a message. Correcting a published announcement republishes it and makes the corrected copy eligible again, including for people who saw the earlier copy.

Select **Archive** to end a published announcement. Ending a Sticky announcement asks for confirmation because it removes the message for everyone immediately. An archived announcement is retained in the administration list and may be republished; republishing starts its display receipts again.

## What not to do

- Do not use an announcement as an approval step or as proof that a person completed work.
- Do not put passwords, access tokens, private keys, or unnecessary personal information in a message.
- Do not choose Sticky for routine information that no longer needs to remain on every page.
- Do not expect a role-targeted message to reach a person after that role is removed.
- Do not use the legacy `super-admin` role name as authority. Announcement management belongs only to the protected System owner.
