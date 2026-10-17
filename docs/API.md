# API and Webhooks

## Personal API tokens

Users create and revoke their own tokens at **Settings → API tokens**. A token is shown only once. Give each integration the smallest required set of abilities:

- `submissions:read` — read accessible submissions and the current user.
- `submissions:write` — create drafts and submit owned submissions.
- `approvals:act` — record an action only when the user is assigned to the current workflow step.
- `request-types:read` — read published request-type schemas.

Send tokens as a Bearer token. All versioned endpoints are throttled to 60 requests per minute per authenticated user. Repeat a mutating POST with the same Idempotency-Key header to receive the original response; a different body with the same key returns 409. While the first request with a key is still running, a repeat returns 409 with `Retry-After: 1`; retry it to get the original response. A key whose request failed with a 5xx error can be reused. Keys are at most 255 characters (longer keys return 422).

The machine-readable contract is at `GET /api/v1/openapi.json` (also stored in `docs/openapi-v1.yaml`).

## Version 1 endpoints

| Method | Path | Required ability | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/user` | `submissions:read` | Current token owner and granted abilities |
| `GET` | `/api/v1/request-types` | `request-types:read` or `submissions:read` | Published request-type schemas |
| `GET` | `/api/v1/request-types/{slug}` | `request-types:read` or `submissions:read` | One published schema |
| `GET` | `/api/v1/submissions` | `submissions:read` | Submissions the token owner can see: all of them for people who see every request (and auditors with an active grant); otherwise their own, ones they follow, are assigned to or delegated, or have acted on. Accepts `status`, `type`, `per_page`, and `cursor` |
| `POST` | `/api/v1/submissions` | `submissions:write` | Create a draft; include `submit: true` to submit immediately |
| `GET` | `/api/v1/submissions/{id}` | `submissions:read` | One accessible submission, workflow, and audit actions |
| `POST` | `/api/v1/submissions/{id}/submit` | `submissions:write` | Submit an owned eligible draft/returned submission; send `comment` (required when answering an information request) |
| `POST` | `/api/v1/submissions/{id}/actions` | `approvals:act` | Record an assigned approver action through the workflow engine |

Workflow actions accept `approve`, `reject`, `return`, `request_information`, `escalate`, and `comment`. The engine enforces the current step, delegation, duplicate-action, and comment requirements; the API never updates workflow state directly.

If a submission cannot be sent for approval (for example no approver can be resolved, separation of duties leaves only the requester, or a required custom field is empty), both submit endpoints answer 422 with the reason under `errors.submit`. `POST /api/v1/submissions` with `submit: true` is all-or-nothing: on a 422 nothing is created.

When creating a submission, `department_id` may only be your own department (or omitted); other departments are refused because the department decides department-manager routing.

Send the optional `step_id` (the `workflow.current_step.id` you read before deciding). If the request has moved to another step in the meantime, the action is refused with a 422 instead of being applied to the new step. Clients that retry requests should always send it.

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

Deliveries are recorded before network I/O. A non-2xx response or connection failure is tried five times in all: the first attempt and four retries after 1 minute, 5 minutes, 15 minutes, and 1 hour; delivery outcome, response status, body excerpt, and last error remain in the audit record.
