# AGENTS.md — Guidance for AI Coding Agents

This file gives any AI agent (or human contributor) the essential context to work on **ApproveDesk** safely and consistently. Read it before writing any code. Detailed companion docs:

- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — what gets built, in what order, with acceptance criteria
- [docs/CI-CD.md](docs/CI-CD.md) — pipelines, branching, releases, deployment automation
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — local environment setup and commands
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — installer runbook, cPanel/Plesk procedures

## Project Overview

ApproveDesk is an approval workflow application: organizations submit documents, notes, and memos that route through configurable approval chains (single approver, sequential, parallel, quorum N-of-M, conditional, auto-approve, delegation). It provides an immutable audit trail, notifications, and dashboards. It **must run on ordinary shared hosting (cPanel/Plesk)** — no Docker, no Redis required, database queue driver, one cron entry.

## Stack (locked decisions — do not change without asking)

| Component   | Decision                                          |
|-------------|---------------------------------------------------|
| Framework   | Laravel 13.x (PHP 8.3–8.5)                        |
| Frontend    | Livewire 3 + Alpine.js + Tailwind CSS             |
| Database    | MySQL 8.0+/MariaDB 10.6+ in production; SQLite for dev/tests |
| Queue/Cache | `database` driver (file cache) — Redis only optional later |
| Auth        | Official Livewire starter kit + spatie/laravel-permission |
| Files       | Local disk, configurable to S3/R2; served via signed URLs |

**Laravel 12 fallback only** if a target host maxes out at PHP 8.2. Laravel 11 from the original spec is superseded.

## Essential Commands

```bash
composer install                          # PHP dependencies
npm install && npm run dev                # assets (Vite dev server)
php artisan migrate --seed                # database (SQLite by default in dev)
php artisan test                          # run the full test suite
php artisan test --parallel               # faster, use before pushing
./vendor/bin/pint --dirty                 # lint/format changed files ONLY
php artisan migrate:fresh --seed          # reset dev database
```

**Before you consider any task done:** `php artisan test --parallel` passes and `./vendor/bin/pint --dirty` is clean. CI runs the same checks and rejects anything less.

## Architecture

Layered structure. Respect the boundaries:

```
app/
  Actions/        # single-responsibility, invokable (CreateSubmissionAction)
  DTOs/           # readonly data objects (SubmissionData, WorkflowActionData)
  Services/       # WorkflowEngine, SubmissionService, NotificationService, ReportService
  Events/ Listeners/ Jobs/   # async side effects only — no business rules
  Models/ Policies/ Http/ Livewire/
```

- **HTTP layer stays thin**: validation in Form Requests, authorization in Policies, orchestration in Actions/Services. No business logic in controllers or Livewire components.
- **Every model relationship used in a view must be eager loaded** — check for N+1 before finishing any list view.

## The Workflow Engine — invariants you must never break

`App\Services\WorkflowEngine` is the core domain. Everything else in the app is plumbing around it.

1. **`workflow_actions` is append-only.** Never write code that UPDATEs or DELETEs rows in it. Current workflow state is *derived* by replaying actions. This is the compliance/tamper-evidence guarantee.
2. **All state transitions go through the engine.** No Livewire component, controller, job, or command may mutate `workflow_instances` directly — only `WorkflowEngine::initialize()` / `processAction()` / internal private methods.
3. **Every mutation runs inside a DB transaction with `lockForUpdate()` on the instance** — prevents double-approval races.
4. **Verify the actor is assigned to the *current* step** (or a valid delegate) before accepting any action; reject actions on stale steps.
5. **One `WorkflowInstance` per submission** (unique constraint), **no duplicate actions** by the same actor on the same step.
6. Every topology (single, sequential, parallel, quorum, conditional, auto-approve, delegation) must have feature tests in `tests/Feature/Workflow/` — a topology without tests is not implemented.

## Coding Rules

**Do:**
- Use PHP enums (backed by string values) for all status/type fields, with string DB columns + app-level validation. **Never use MySQL `ENUM` columns** — they break SQLite tests and are painful to alter.
- Gate every controller/Livewire action with a Policy class. Never check role names directly in controllers ("`$user->hasRole('admin')`" in a controller is a bug; put it in a Policy).
- Validate all input via Form Requests with strict types.
- Sanitize rich-text HTML server-side before storage.
- Generate reference numbers with a retry loop around the unique constraint (race-safe).
- Write tests first for engine logic; keep coverage ≥ 80% overall, 100% on `WorkflowEngine`.

**Don't:**
- Don't add dependencies on Redis, Docker, Node at runtime, or long-running processes — shared hosting constraint.
- Don't put secrets in code; everything environment-driven via `config/` + `.env`.
- Don't use raw SQL string interpolation; Eloquent/parameterized query builder only.
- Don't serve uploaded files directly from the webroot; use signed temporary URLs.
- Don't update migrations that have been merged/released — write new ones.

## Testing Policy

- Pest (skeleton default). Feature tests per topology, unit tests for engine/actions/services, factories for all models.
- Tests run on SQLite in-memory — they must not depend on MySQL-only behavior.
- CI enforces `--min=80` coverage on `app/`; `WorkflowEngine` is expected at 100%.
- Browser tests (Laravel Dusk) only for the submission wizard and approval modal flows; keep the set small.

## CI/CD Contract

- Branch off `main` (short-lived `feature/*` branches), open a PR. CI must be green before merge.
- Deploys: `main` → staging automatically; production only from `v*` tags. See [docs/CI-CD.md](docs/CI-CD.md).
- First run on a fresh server is done through the **web installer at `/install`** (requirements check → database → migrations → first admin). Never seed production credentials in code.

## Installer & Branding Constraints

- The installer must self-disable after completion (lock file at `storage/app/installed.lock`; middleware refuses `/install` routes once it exists).
- Login page branding (background image, logo, accent color, tagline, custom CSS) lives in the `settings` table and is read through a cached settings helper — never query per request.
