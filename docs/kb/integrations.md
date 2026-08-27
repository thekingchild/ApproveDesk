# Integration hub

Open **Administration → Integrations** to connect another system and run work after a request is approved.

## Connectors

A connector is one destination: Microsoft Teams, Slack, Google Drive, SharePoint/OneDrive, Gmail/Outlook, or generic HTTP.

1. Add a connector and give it a name.
2. Save credentials on the connector card. Tokens are encrypted and never shown again. Leave a field blank to keep the current secret. Destination URLs must be public HTTPS.
3. Test the connection. Failures show only that the destination returned an error, not the response body.
4. Enable the connector when you are ready.

Unconfigured or disabled connectors skip. They do not block the approval.

## Post-approval actions

An action runs when a workflow completes (the request is approved). Choose:

- Upload file
- Send email
- Create or update an external record
- Invoke a signed webhook

You can limit an action to one request type, or leave it for every type. Failed deliveries retry on the queue, then sit in a dead-letter list. **Replay** sends a dead-letter again without creating a second record. A completed delivery is not sent twice.

Unconfigured or disabled connectors skip. They do not block the approval. Live destinations are not required for ApproveDesk to record the attempt.

Generic HTTP and signed webhooks use the same signing method as **Administration → Webhooks**.

## What not to do

Do not paste a personal bot token into a screenshot or a support ticket. Credentials go through the save form and stay hidden.

A domain or URL allow-list on registration is unrelated — this hub is for outbound work after approval.

See [Webhooks](webhooks.md) for event-by-event outbound notifications that are not post-approval actions.
