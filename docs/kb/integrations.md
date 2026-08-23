# Integration hub

Open **Administration → Integrations** to connect another system and run work after a request is approved.

## Connectors

A connector is one destination: Microsoft Teams, Slack, Google Drive, SharePoint/OneDrive, Gmail/Outlook, or generic HTTP.

1. Add a connector and give it a name.
2. Save credentials on the connector card. Tokens are encrypted and never shown again. Leave a field blank to keep the current secret.
3. Test the connection. Failures are stored with secrets stripped out.
4. Enable the connector when you are ready.

Unconfigured or disabled connectors skip. They do not block the approval.

## Post-approval actions

An action runs when a workflow completes (the request is approved). Choose:

- Upload file
- Send email
- Create or update an external record
- Invoke a signed webhook

You can limit an action to one request type, or leave it for every type. Failed deliveries retry, then sit in a dead-letter list. Replay sends them again.

Generic HTTP and signed webhooks use the same HMAC as **Administration → Webhooks**: `HMAC_SHA256(secret, timestamp + "." + body)`.

## What not to do

Do not put a personal bot token in a Livewire field screenshot or a ticket. Credentials go through the save form and stay encrypted.

A domain or URL allow-list on registration is unrelated — this hub is for outbound work after approval.

See [Webhooks](webhooks.md) for event-by-event outbound notifications that are not post-approval actions.
