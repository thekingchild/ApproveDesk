# API and Webhooks

## Personal API tokens

Users create and revoke their own tokens at **Settings → API tokens**. A token is shown only once. Give each integration the smallest required set of abilities:

- `submissions:read` — read accessible submissions and the current user.
- `submissions:write` — create drafts and submit owned submissions.
- `approvals:act` — record an action only when the user is assigned to the current workflow step.

Send tokens with `Authorization: Bearer <token>`. All versioned endpoints are throttled to 60 requests per minute per authenticated user.

## Version 1 endpoints

| Method | Path | Required ability | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/user` | `submissions:read` | Current token owner and granted abilities |
| `GET` | `/api/v1/submissions` | `submissions:read` | Accessible submissions; accepts `status`, `type`, and `per_page` |
| `POST` | `/api/v1/submissions` | `submissions:write` | Create a draft; include `submit: true` to submit immediately |
| `GET` | `/api/v1/submissions/{id}` | `submissions:read` | One accessible submission, workflow, and audit actions |
| `POST` | `/api/v1/submissions/{id}/submit` | `submissions:write` | Submit an owned eligible draft/returned submission |
| `POST` | `/api/v1/submissions/{id}/actions` | `approvals:act` | Record an assigned approver action through the workflow engine |

Workflow actions accept `approve`, `reject`, `return`, `request_information`, `escalate`, and `comment`. The engine enforces the current step, delegation, duplicate-action, and comment requirements; the API never updates workflow state directly.

## Outbound webhooks

Administrators configure trusted HTTPS endpoints at **Administration → Webhooks**. Endpoints can subscribe to:

`submission.created`, `workflow.advanced`, `workflow.completed`, `workflow.rejected`, `workflow.returned`, `workflow.information_requested`, and `workflow.delegated`.

Each delivery is an HTTP `POST` with a JSON body and these headers:

| Header | Meaning |
|---|---|
| `X-ApproveDesk-Event` | The subscribed event name |
| `X-ApproveDesk-Delivery` | A unique delivery UUID; use it for idempotency |
| `X-ApproveDesk-Timestamp` | Unix timestamp at signing time |
| `X-ApproveDesk-Signature` | `sha256=` followed by an HMAC-SHA256 signature |

Verify the signature against the exact raw request body using:

```text
HMAC_SHA256(secret, timestamp + "." + raw_body)
```

Deliveries are recorded before network I/O. A non-2xx response or connection failure retries five times with increasing delays (1 minute, 5 minutes, 15 minutes, and 1 hour); delivery outcome, response status, body excerpt, and last error remain in the audit record.
