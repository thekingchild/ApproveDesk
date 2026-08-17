# ApproveDesk — Implementation & Execution Plan

Execution plan derived from the approved architecture document (`ApprovalWorkflow_Implementation.docx`), updated with the locked decisions: **Laravel 13** (current stable, PHP 8.3+), a **web-based installer wizard**, and a **brandable login page**. Conventions and invariants for contributors/agents live in [AGENTS.md](AGENTS.md).

Guiding principle: **build the workflow engine first, test-first, in isolation.** Everything else is standard Laravel plumbing; the engine is the product. Each phase ends with a demo-able, deployable increment — no phase depends on unfinished work.

---

## Phase 0 — Toolchain, Scaffold, CI Foundation

**Goal:** a running, tested, CI-gated skeleton.

| # | Task |
|---|------|
| 0.1 | Install local toolchain: PHP 8.3 (cli, sqlite3, mbstring, xml, curl, zip, bcmath, gd), Composer 2, Node 22 (already present) |
| 0.2 | `git init`; scaffold Laravel 13 with the Livewire starter kit; commit before any customization |
| 0.3 | Add CI pipeline (lint + tests + asset build) — see [docs/CI-CD.md](docs/CI-CD.md); wire `.env.example` for SQLite dev |
| 0.4 | Add spatie/laravel-permission, spatie/laravel-activitylog, pint config; baseline test suite green |

**Acceptance:** `php artisan test --parallel` passes in a fresh clone after `composer install && npm install`; CI green on `main`; README quick-start works verbatim.

## Phase 1 — MVP (target: 6–8 weeks per spec)

**Goal:** an organization can run real sequential approvals end-to-end.

### 1A. Schema & domain foundation
- All migrations from spec §3: `departments`, `users`, `submissions`, `workflow_templates`, `workflow_template_steps`, `workflow_instances`, `workflow_actions`, `submission_files`, `settings`, `comments`, `tags` + spatie tables. String columns + PHP enums (no MySQL ENUMs).
- Models with relationships, factories, and the six seeded roles: super-admin, admin, department-head, approver, submitter, viewer.
- Race-safe reference number generation (`SUB-2026-00042` style).

### 1B. Workflow engine (sequential only) — TDD
- `WorkflowEngine::initialize() / processAction() / advance()` with transaction + `lockForUpdate`, stale-step rejection, duplicate-action rejection.
- Actions: approve, reject, return-for-revision, comment. Derived state from the append-only `workflow_actions` log.
- Events: `SubmissionCreated`, `WorkflowAdvanced`, `WorkflowCompleted`.

**Acceptance (tests, not opinions):** single-approver and multi-step sequential feature tests pass; concurrent double-approve test proves idempotency; reject terminates and notifies; return-for-revision resets to submitter while preserving history; `WorkflowEngine` at 100% coverage.

### 1C. Web installer & branded auth
- `/install` wizard (Livewire): requirements check → database credentials + connection test → write `.env`, `key:generate`, `migrate --force`, seed → create first super-admin → finish. Lock file disables it afterward.
- Stylish login page: split layout, default gradient background, clean card. Admin → Settings → Branding: background image upload (with dark overlay), logo, accent color, app name/tagline, custom CSS, registration toggle. Cached settings helper; live preview in admin.

**Acceptance:** fresh deploy reachable only through `/install` until configured; after completion `/install` returns 404; login page reflects branding changes with no code deploy; installer covered by feature tests.

### 1D. Submissions & approvals UI
- Multi-step wizard (details → attachments → review & submit) with 30s draft auto-save; MIME-validated uploads stored outside webroot, served via signed URLs.
- Approver inbox (To Action / Pending Others / Completed) + vertical workflow timeline (grey/blue/green/red).
- Submission status page: metadata, progress, timestamped action history, comment thread.
- Email notifications (queued): action required, approved, rejected, returned.

### 1E. Dashboards, roles & hardening
- Submitter/approver/admin dashboards; policies on every action; login rate limiting (5/min/IP); scheduler entries (`schedule:run` every minute; `queue:work --stop-when-empty` for shared hosting).

**Phase 1 exit:** end-to-end demo — install on a clean target, create users, submit, approve through 3 levels, reject with comment, full audit trail visible; ≥80% coverage; deployed to staging via CI.

## Phase 2 — Full Topologies & Collaboration (4–6 weeks)

- Parallel (all-of) and quorum (N-of-M) step types + tests.
- Delegation with time limits, `PurgeExpiredDelegations` daily job, delegation modal.
- Conditional branching (JSON rule evaluation on submission data) and auto-approve steps.
- TipTap rich text (server-side sanitized), in-browser PDF/image preview, advanced search (status, type, date, submitter, department).
- Request-Information pause/resume; escalate action; digest email preferences.

**Exit:** every topology in spec §2.3 has green feature tests and works in the UI; delegation expiry verified by time-travel test (`Carbon::setTestNow`).

## Phase 3 — SLA, Analytics, API (4–6 weeks)

- `timeout_hours` + `timeout_action` enforcement via `ProcessEscalation` (every 15 min); SLA config admin UI; overdue indicators.
- Admin analytics (approval rates, turnaround, bottleneck detection) with charts; CSV/Excel/PDF exports.
- Public REST API behind Sanctum tokens; outbound webhooks on state changes.

## Phase 4 — v2 Access & Deployment (complete)

- Google and Microsoft OpenID Connect login with OAuth state validation and PKCE.
- Email approval links with an administrator-selectable 1–720 hour lifetime, signed confirmation/action URLs, assignment policies, throttling, and stale-step rejection.
- Production Docker image and Compose stack for EasyPanel, Coolify, and compatible container platforms; cPanel and Plesk remain first-class deployment targets.

**Exit:** provider and approval-link feature tests are green; the container configuration validates; full CI, static analysis, formatting, and production asset builds pass.

Potential work after v2 is intentionally not part of this implementation. See [ROADMAP.md](ROADMAP.md).

---

## Milestone & Release Cadence

| Milestone | Tag | Contents |
|---|---|---|
| M0 | `v0.1.0` | Scaffold + CI |
| M1 | `v1.0.0` | Phase 1 MVP |
| M2 | `v1.1.0` | Phase 2 topologies |
| M3 | `v1.2.0` | Phase 3 SLA/analytics/API |
| M4 | `v2.0.0` | Signed email approvals, Google/Microsoft login, container deployment |

Tags deploy to production automatically (see [docs/CI-CD.md](docs/CI-CD.md)). CHANGELOG.md updated with every release.

## Risk Register (top items & mitigations)

| Risk | Mitigation |
|---|---|
| Race conditions in approvals | Engine-wide transactions + row locks; dedicated concurrency tests (Phase 1B) |
| Shared-hosting incompatibility | Database queue + file cache only; requirements check in installer; staging on target host before each release |
| Spec drift / scope creep | Phase exit criteria are tests; new ideas go to Phase 2+ backlog |
| Audit tampering | Append-only actions enforced in code; DB-user privileges restricted to INSERT/SELECT on `workflow_actions` at deploy |
| Laravel 13 ecosystem gaps (young release) | Lock versions in `composer.lock`; fallback path to Laravel 12 documented (PHP 8.2 hosts) |
