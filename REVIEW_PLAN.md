# ApproveDesk — Code Review & Remediation Plan

**Reviewer role:** project code reviewer for everything shipped through **v2.8.6** and for the remaining plan (Phases 10–15).
**Created:** 2026-10-12 · **Baseline commit:** `4131fa1` (`chore: release v2.8.6`)
**Status:** Wave 0 — Complete (2026-10-12) · Wave 1 — **Complete (2026-10-16)**: all S1/S2 and the Wave 1 S3 findings have fixes in review (PRs #24–#46); deferred with reasons: RV-SEC-15 (CSP, Wave 3 ADR), Teams Bot Framework JWT verification, RV-FEAT-01 (reassign step), RV-ENG-10/11 (suspected, unverified). MariaDB 10.6 CI green from #36 · Wave 2 — Next
**Companion docs:** [AGENTS.md](AGENTS.md) · [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) · [docs/SECURITY.md](docs/SECURITY.md) · [docs/PERFORMANCE.md](docs/PERFORMANCE.md)

This plan defines how the review runs, what each review track checks, how findings are recorded and fixed, and what "done" means. It is written so any engineer or agent can pick up a track without prior conversation context.

---

## 1. Objectives

1. **Architectural consistency:** the code follows the layering and invariants in AGENTS.md (thin HTTP/Livewire, Actions/Services, Policies, enums, append-only audit).
2. **Bug detection:** find and fix correctness defects, especially in workflow state, authorization, money-adjacent/tamper-evidence paths, timezones, and MySQL/MariaDB/SQLite parity.
3. **Performance:** remove N+1 queries, unbounded queries, missing indexes, and shared-hosting bottlenecks.
4. **Security:** verify authorization, input/output handling, file access, outbound requests, secrets, and authentication flows.
5. **Laravel best practice:** idiomatic Laravel 13 / Livewire 4 usage, static analysis debt reduction, test quality.
6. **UI/UX:** bring the application to the standard expected of approval and compliance software (section 7).
7. **Forward readiness:** review Phases 10–15 against the current code and fix plan gaps before v3.0.0 work starts.

**Non-goals:** changing locked stack decisions, adding Redis/Docker/runtime Node dependencies, or starting Phase 10 implementation.

---

## 2. Ground rules for the review

| Rule | Detail |
|---|---|
| Branching | `feature/rv-<track>-<short-name>` off `main`. One finding or tightly coupled group per PR. |
| Regression first | Any bug touching authorization, workflow state, audit integrity, tenancy, or data loss gets a failing test **before** the fix (IMPLEMENTATION_PLAN §5). |
| Migrations | Never edit a released migration. Schema fixes are new, additive migrations with a tested backfill. |
| Engine invariants | No fix may UPDATE/DELETE `workflow_actions` or mutate `workflow_instances` outside `WorkflowEngine`. |
| Help centre | Any change to a screen's behaviour or wording updates `docs/kb/` + `HelpCatalogue` in the same PR. |
| Definition of done per PR | `php artisan test --parallel` green · `./vendor/bin/pint --dirty` clean · `composer types:check` green · no new PHPStan baseline entries · `CHANGELOG.md` entry. |
| Architecture changes | Any change to a shared pattern (e.g. how Livewire pages call Actions, settings caching) gets an ADR in `docs/adr/`. |
| Releases | Fixes ship as patch releases (`v2.8.7`, `v2.8.8`, …) — decided 2026-10-12. Larger refactors may ship as `v2.9.0`. Production only from `v*` tags. |

### 2.1 Severity scale

| Severity | Meaning | Response |
|---|---|---|
| **S1 Critical** | Exploitable security flaw, audit-trail tampering, cross-user data exposure, data loss, approval granted by the wrong actor | Fix immediately, own patch release |
| **S2 High** | Wrong workflow outcome, authorization gap requiring an authenticated user, broken core flow on a supported DB, major perf failure | Fix in current wave |
| **S3 Medium** | Architectural violation, N+1 on a list view, missing UI state, a11y failure on a primary flow | Batch per track |
| **S4 Low** | Style, naming, minor duplication, cosmetic UI | Log; fix opportunistically |

### 2.2 Findings register

All findings go into `docs/review/FINDINGS.md` (created in Wave 0) using this format:

```
### RV-<TRACK>-<NN> — <title>
- Severity: S1–S4 · Status: Open | In progress | Fixed (PR/commit) | Won't fix (reason)
- Location: path/to/file.php:123
- Evidence: what was observed / reproduction
- Impact: who is affected and how
- Fix: approach taken; regression test name
```

Track codes: `ENG` engine/audit · `SEC` security · `ARC` architecture · `BUG` correctness · `PERF` performance · `LAR` Laravel practice · `DATA` schema/migrations · `TEST` test suite · `UX` UI/UX · `DOC` docs drift · `FWD` remaining-plan review.

---

## 3. Codebase profile (as of v2.8.6)

| Area | Size |
|---|---|
| `app/` | ~30k lines: 99 Actions, 92 Services, 63 Models, 63 HTTP classes, 31 Support, 11 Policies, 32 Enums, 19 DTOs |
| Livewire | 53 page components under `resources/views/pages/` (single-file, `pages::`), 4 classes in `app/Livewire/` |
| Tests | 170 test files (Feature + Unit) |
| Static analysis | PHPStan/Larastan level 7, **87 baseline entries** |
| CI | lint, types, `composer audit`, parallel tests, coverage gate 80% (`app/`) and **97%** on `WorkflowEngine`, MySQL job |
| ADRs | 3 |

---

## 4. Initial reconnaissance — leads to verify first

These came from a first-pass scan. They are **leads, not confirmed findings**; each is verified in its track and either logged or closed.

| # | Lead | Where | Track |
|---|---|---|---|
| L1 | `json_extract(...)` compared with `whereColumn` to a plain string column. MySQL converts for JSON comparison, but **MariaDB returns the quoted JSON string** (`"field_key"`), so form-field assignees may never match on MariaDB 10.6+ (a supported target). | `app/Services/Workflow/AssignedStepConstraint.php:71` | BUG / DATA |
| L2 | Very large Livewire page components likely carrying business logic: `submissions/show` (1,223 lines), `admin/request-types` (966), `admin/workflow-templates` (772), `admin/workflow-builder` (748), `admin/users` (569), `settings/branding` (508). | `resources/views/pages/**` | ARC |
| L3 | 26 page components have no in-component authorization (rely on route `can:` middleware only). Confirm Livewire persistent middleware re-applies `can:` on `/livewire/update` and that every mutating action authorizes. Only 12 `#[Locked]` properties exist across all pages — check for tamperable IDs in public properties. | `resources/views/pages/**` | SEC |
| L4 | 13 raw `{!! !!}` outputs. Verify every write path sanitizes (announcements, revision diff before/after body, report body, Help HTML, theme CSS variables from custom themes). | `announcement-banner`, `submissions/show:759,763`, `reports/approval:126`, `partials/head:35,40` | SEC |
| L5 | `hasRole`/`hasAnyRole` outside Policies in 7 files, including `ReportService`, `UpdateUserAction`, `AnonymizeUserAction`. AGENTS.md requires permission/Policy checks. | `app/Services/ReportService.php` etc. | ARC / SEC |
| L6 | Settings are cached **per key** with `rememberForever` on the file cache. `Branding` alone reads ~10 keys per request, i.e. ~10+ file reads per page on shared hosting. | `app/Models/Setting.php:32`, `app/Support/Branding.php` | PERF |
| L7 | 40 `lockForUpdate()` calls outside `WorkflowEngine` — review lock ordering, deadlock risk, and whether each runs inside a transaction. | `app/**` | ENG / BUG |
| L8 | AGENTS.md says 100% coverage on `WorkflowEngine`; CI gate is 97%. Either raise coverage or correct the doc. | `.github/workflows/ci.yml`, `AGENTS.md` | TEST / DOC |
| L9 | Only 3 ADRs although many architectural decisions were made (owner model, custom roles ceiling, themes, backups/promote, PWA, approval IDs). | `docs/adr/` | DOC |
| L10 | `Dockerfile` / `compose.yaml` exist in a project whose locked constraint is shared hosting. Confirm they're dev/optional only and nothing at runtime depends on them. | repo root | ARC |

---

## 5. Execution waves

```
Wave 0  Baseline & tooling            ──► 1 day
Wave 1  Engine integrity + Security   ──► S1/S2 fixes ship as v2.8.7+
Wave 2  Correctness + Performance     ──► patch releases
Wave 3  Architecture + Laravel practice ─► refactors behind tests, v2.9.0 branch
Wave 4  UI/UX for approval & compliance ─► v2.9.0
Wave 5  Remaining-plan review (Ph 10–15) ─► plan amendments + "Phase 9.9 readiness" tasks
```

Waves 1 and 2 can overlap (different files). Wave 3 refactors must not start on a file until its Wave 1/2 findings are fixed, so fixes aren't lost in a rewrite. Read-only review of each track can run in parallel; fixes land sequentially per file area.

### Wave 0 — Baseline — Complete (2026-10-12)

1. **Complete:** recorded `php artisan test --parallel` (949 tests, 2 m 20 s), coverage from CI (85.66% `app/`, 97.10% `WorkflowEngine`; no local coverage driver), `composer types:check`, `pint --test`, `composer audit`, `npm audit --omit=dev`.
2. **Carried to Wave 1:** local MySQL 8 / MariaDB 10.6 runs were not possible (no Docker access on this machine). **MariaDB 10.6 CI job added** (decision Q5); MySQL already in CI. Results on the first PR run.
3. **Complete:** route snapshot (145 routes) generated with `route:list --json` for the SEC track.
4. **Complete:** query-scaling probe at 3 vs 30 rows with `preventLazyLoading` on → five pages scale with data (RV-PERF-01…05).
5. **Complete:** `database/seeders/PerformanceSeeder.php` (local-only, engine-driven). Throughput ≈ 0.4 s/submission on SQLite, so the working dataset is 300 users / 3,000 submissions in `database/perf.sqlite` rather than 20k.
6. **Partially complete:** CI history shows every browser/axe test failing since ≥ 2026-08-31 (RV-TEST-02). A fresh local axe/Lighthouse pass was blocked (browser-pane policy; local chromedriver 151 vs Chrome 155). Carried into Wave 1 with RV-TEST-02.
7. **Complete:** `docs/review/FINDINGS.md` created with baseline, findings, and leads L1–L10.

Also fixed during Wave 0: **RV-TEST-01** (tests could wipe the local dev database when config was cached).

**Exit:** baseline numbers recorded at the top of FINDINGS.md.

---

## 6. Review tracks and checklists

### 6.1 `ENG` — Workflow engine & audit integrity (Wave 1, highest priority)

Scope: `app/Services/WorkflowEngine.php`, `app/Services/Workflow/**`, `WorkflowAction`/`WorkflowInstance` models, every caller of the engine, related jobs/commands (timeouts, escalation, SLA, resume).

- [ ] Grep the whole codebase (including Livewire pages, jobs, commands, seeders, backup restore/promote) for any write to `workflow_actions` other than insert, and any write to `workflow_instances` outside the engine.
- [ ] Is append-only enforced at the model level (e.g. `updating`/`deleting` guards throwing) and covered by `WorkflowActionImmutabilityTest`? Check mass/query-builder updates, which bypass model events.
- [ ] Every public engine method: transaction + `lockForUpdate()` on the instance, re-read state after lock, stale-step check, actor-assignment/delegate check, duplicate-action check.
- [ ] Delegation: time windows (`starts_at`/`expires_at`) in app timezone vs UTC; delegation chains/cycles; delegate acting on own submission.
- [ ] Separation of duties: can a requester approve their own submission through any assignee resolver (department manager, form field, role, delegate)?
- [ ] Quorum/parallel: concurrent final approvals — exactly one transition. Write a concurrency test simulating two actors.
- [ ] Conditional/selection rules: behaviour when a referenced field is deleted in a newer form version; old instances still complete.
- [ ] Timeout/escalation jobs: idempotent if the scheduler runs twice (shared-host cron overlap), `withoutOverlapping`/unique jobs.
- [ ] Email approval links: single use, bound to step + actor, rejected after step advances, E2FA honoured.
- [ ] Reference-number and approval-ID generation: retry loop on unique constraint, behaviour under concurrency, configurable formats can't collide.
- [ ] Backup restore / SQLite promote: can they rewrite audit history? If yes, is the event itself audited and owner-only?
- [ ] Lead **L7** (locks outside the engine) and **L8** (coverage target).
- [ ] Topology test matrix in `tests/Feature/Workflow/` covers: single, sequential, parallel, quorum, conditional, auto-approve, delegation, return-for-revision, information request, timeout.

### 6.2 `SEC` — Security (Wave 1)

Method: walk the route snapshot endpoint by endpoint, then every Livewire public method, then every outbound/inbound integration. Reference [docs/SECURITY.md](docs/SECURITY.md) and ADR-0002 threat models, and confirm each previously remediated finding (SH-SEC272, SH-SEC282) still holds.

**Authorization**
- [ ] Every route has `auth` + permission/policy middleware or an explicit public justification.
- [ ] Every Livewire action that mutates state calls `$this->authorize()` or an Action that does. Route middleware alone is not enough for actions with model parameters.
- [ ] Public properties holding IDs/models that must not change are `#[Locked]` (lead **L3**).
- [ ] Policies cover viewing submissions as: requester, current approver, past approver, follower, mentioned user, auditor, department manager, admin, owner. No IDOR through attachments, comments, revisions, reports, exports, or the API.
- [ ] Role ceiling (custom roles cannot grant above the granter) and owner protection cannot be bypassed via import, API, or Livewire property tampering.
- [ ] Lead **L5** — move role-name checks into permissions/Policies.

**Input/output**
- [ ] Lead **L4** — every `{!! !!}`; sanitizer config (allowed tags/attributes, `javascript:` and `data:` URLs, `style`).
- [ ] Custom themes: CSS variable values validated (no `}` / `</style>` injection).
- [ ] Owner custom code/CSS: owner-only, password-confirmed, second factor — re-verify after SH-FIX286.
- [ ] Spreadsheet/CSV import: formula injection on export, zip-bomb/size limits, XXE in XLSX parsing.
- [ ] Mass assignment: `$fillable`/`$guarded` on models written from user input.
- [ ] Validation lives in Form Requests or Livewire rules with strict types; no `request()->all()` into models.

**Files**
- [ ] Attachments, branding assets, backups, reports: never under the webroot; signed, expiring URLs; content-type and extension allow-list; `Content-Disposition` and `X-Content-Type-Options: nosniff`; SVG uploads either rejected or sanitized.
- [ ] Path traversal in `GET /branding/assets/{file}` and backup downloads.

**Authentication**
- [ ] Login, 2FA, passkeys, OAuth (state, PKCE, domain allow-list), registration OTP, password reset — rate limits, enumeration resistance, session regeneration, MFA enforcement for every role it applies to.
- [ ] Owner recovery flows, Turnstile fallback when Cloudflare is down.
- [ ] Session/device revocation actually invalidates sessions in the database session driver.

**Integrations**
- [ ] Outbound SSRF guard (`OutboundDestination`) applied to every outbound client: webhooks, web push, Teams/Slack/Telegram/WhatsApp, backup destinations, OAuth, update checks; DNS rebinding (resolve-then-connect).
- [ ] Inbound webhooks (Telegram etc.): signature/secret verification, replay protection, throttling.
- [ ] API: token scopes enforced per endpoint, pagination limits, no sensitive fields in resources.
- [ ] Secrets stored encrypted (`encrypted` casts), never logged, never returned to the browser after save.

**Platform**
- [ ] Security headers/CSP (CSP must accommodate owner custom code deliberately).
- [ ] Installer lock and in-app updater cannot be re-entered; update path cannot be used to run arbitrary migrations from uploads.
- [ ] `composer audit` / `npm audit` clean or justified.

### 6.3 `ARC` — Architectural consistency (Wave 3; inventory in Wave 1)

- [ ] **Layering:** for each large page component (lead **L2**) list what it does beyond binding/validation/authorization/calling an Action. Extract business rules into Actions/Services with unit tests; leave the component as orchestration.
- [ ] Controllers: same check across `app/Http/Controllers/**`.
- [ ] `app/Support/` (31 files) vs `app/Services/` (92): define the boundary (Support = stateless helpers/value readers; Services = domain behaviour) and move misfiled classes.
- [ ] DTOs used consistently at Action boundaries (19 DTOs vs 99 Actions — map which Actions take raw arrays).
- [ ] Events/Listeners/Jobs contain no business rules (AGENTS.md).
- [ ] Every status/type field is a backed PHP enum with a cast; no stray string literals for statuses in views or queries.
- [ ] Exceptions: domain exceptions (`WorkflowException`, `CollaborationException`) translated to user messages in one place, not ad-hoc per component.
- [ ] Duplicate logic detection: approval eligibility, "who can see this submission", timezone formatting, file URL signing — each should have one implementation.
- [ ] Single-file Livewire pages vs `app/Livewire` classes: document the convention (ADR) and apply it.
- [ ] Lead **L10** (Docker files) and lead **L9** (backfill missing ADRs).
- [ ] **Tenancy readiness smells** (feeds Wave 5): global singletons (`Setting`, `Branding`, owner, site capacity), global cache keys, storage paths without a scope, reference sequences, scheduler commands that iterate "everything".

### 6.4 `BUG` — Correctness by feature area (Wave 2)

Review each area by reading the code against its Help article (`docs/kb/`) and plan acceptance criteria, then probing edge cases with tests.

| Area | Focus |
|---|---|
| Submissions & drafts | Draft → submit transition; editing after return; attachments on revision; General Request save-draft-only; reference numbers |
| Request types & form builder | Schema versioning; validation of dates/numbers/required-if; draft-time checks; deleted/renamed fields in old submissions; preview parity with live form |
| Workflow templates & builder | Graph validator (cycles, unreachable nodes, missing end); publishing races; version pinning of in-flight instances |
| Assignment & routing | Lead **L1**; empty assignee sets (no department manager, deactivated user); out-of-office redirection; capacity-limited users |
| Business calendar & SLA | Office hours across DST, holidays, `APP_TIMEZONE` vs user timezone display, due-time estimates, reminder quiet hours |
| Notifications & channels | Digest vs immediate; unconfigured channels skip silently; failed-delivery records; signed URLs in every action-required channel |
| Collaboration | Mentions resolve only to users who can view; edited comments keep history; followers don't receive content they can't see |
| Users & onboarding | Import idempotency, duplicate emails (case), domain allow-list for OAuth and registration, active-user capacity under concurrency |
| Governance | Legal hold blocks disposition; retention jobs; anonymization keeps audit integrity; evidence pack completeness |
| Backups | Integrity digests, restore/promote on SQLite vs MySQL, partial-failure behaviour, local purge |
| Reports | Printable report matches audit trail exactly; stamps; favicon/logo embedding; timezone on printed times |
| API | OpenAPI (`docs/openapi-v1.yaml`) vs actual responses; error format; pagination |

### 6.5 `PERF` — Performance (Wave 2)

Target environment: shared hosting, file cache, database queue, single cron, PHP-FPM with limited memory/time.

- [ ] Enable `Model::preventLazyLoading()` in non-production and run the full suite; log every violation.
- [ ] Query budgets (extend `QueryBudgetTest`) for: dashboard, approvals inbox, submissions index/show, analytics, audit trail, admin users, request types, workflow templates. Target: constant query count regardless of row count.
- [ ] `EXPLAIN` the heaviest queries on MySQL with the large dataset: inbox eligibility (`AssignedStepConstraint` — nested `whereHas` with correlated subqueries), analytics aggregates, global search, audit filters. Add composite indexes via new migrations.
- [ ] Lead **L6** — load all settings in one cached array (single file read), invalidated on write.
- [ ] Pagination everywhere a list can grow; no `->get()` on unbounded tables in UI or jobs; `chunkById`/`lazyById` in jobs and exports; exports streamed.
- [ ] Livewire payload size on large pages (`submissions/show`, builders): avoid serialising big collections into public properties; use `#[Computed]`.
- [ ] Scheduled tasks and queue worker under `schedule:run` every minute: runtime per tick, `withoutOverlapping`, job timeouts below host limits, `queue:work --stop-when-empty` pattern.
- [ ] Attachments/report PDF generation memory and time limits.
- [ ] Front-end: bundle size, Flux/Tailwind purge, image sizes for branding assets, PWA cache strategy.
- [ ] Results recorded in [docs/PERFORMANCE.md](docs/PERFORMANCE.md).

### 6.6 `LAR` — Laravel & Livewire best practice (Wave 3)

- [ ] `env()` only inside `config/`; app works with `config:cache` and `route:cache` (no closures in routes).
- [ ] Form Requests for controller input; Livewire `#[Validate]`/rules for components; consistent authorization style.
- [ ] API Resources for every API response; no models returned raw.
- [ ] Queued mail/notifications implement `ShouldQueue`; jobs define `tries`, `backoff`, `timeout`, uniqueness where needed; failed-job handling surfaces in Operations.
- [ ] `Model::shouldBeStrict()` in local/testing (lazy loading, silently discarded attributes, missing attributes).
- [ ] Casts declared via `casts()` method; `encrypted` casts for secrets; dates as immutable where practical.
- [ ] Factories exist for every model and produce valid states.
- [ ] PHPStan: burn down the 87 baseline entries by category; consider level 8 for `app/Services/WorkflowEngine.php` and `app/Actions/**`.
- [ ] Rector dry-run for Laravel 13 / PHP 8.3 idioms (report only; apply selectively).
- [ ] Livewire 4: `wire:model` modifiers, `#[Computed]` caching, `#[Locked]`, lazy loading for heavy sections, file uploads via temporary URLs with validation.

### 6.7 `DATA` — Schema & migrations (Wave 2)

- [ ] No MySQL `ENUM` columns (confirmed none in first scan); JSON columns queried portably (lead **L1**).
- [ ] Foreign keys and `onDelete` behaviour consistent with audit retention (no cascade that deletes audit evidence).
- [ ] Indexes on every foreign key and common filter (`status`, `current_step_id`, `submitted_at`, `created_at`, polymorphic pairs).
- [ ] Unique constraints backing invariants: one instance per submission, no duplicate actor/step action, reference numbers.
- [ ] Upgrade test: migrate a v2.0.0-era database fixture to head on MySQL, MariaDB, and SQLite.

### 6.8 `TEST` — Test suite quality (continuous)

- [ ] Coverage gaps by directory; target 100% on `WorkflowEngine` (lead **L8**).
- [ ] Each S1/S2 fix has a regression test named after its finding ID.
- [ ] Concurrency tests for approval races and reference generation.
- [ ] Authorization matrix tests (role × screen × action) generated from the route snapshot.
- [ ] Flaky tests identified from repeated `--parallel` runs.
- [ ] Dusk set limited to the submission wizard and approval modal (AGENTS.md); add an axe check to those.

### 6.9 `DOC` — Documentation drift (continuous)

- [ ] AGENTS.md vs reality (coverage gate, Livewire file conventions, Docker files).
- [ ] Help articles match the live screens after every UI change in Wave 4.
- [ ] OpenAPI spec matches the API.
- [ ] Missing ADRs (lead **L9**).

---

## 7. `UX` — UI/UX standard for approval & compliance software (Wave 4)

### 7.1 Target standard

The UI is judged against these principles. Each screen review scores every principle Pass / Partial / Fail.

| # | Principle | What "good" looks like |
|---|---|---|
| P1 | **Decision clarity** | An approver sees, above the fold: what is asked, by whom, amount/impact, due time, their role in this step, and the available decisions. |
| P2 | **Status is unambiguous** | One consistent status vocabulary, colour + icon + text (never colour alone), identical on list, detail, email, report, and API. |
| P3 | **Evidence is visible** | Timeline shows every action with actor, on-behalf-of (delegation), timestamp with timezone, comment, and step. Immutable items are labelled as such. |
| P4 | **Deliberate irreversible actions** | Approve/reject/return need a confirmation that restates the consequence; reject/return require a reason; optional attestation text ("I have reviewed…"); re-authentication where configured. |
| P5 | **Workload management** | Inbox with filters (due soon, overdue, waiting on me, delegated to me), sorting, saved views, counts, keyboard navigation; bulk approve only where policy allows, with per-item confirmation summary. |
| P6 | **SLA & accountability** | Due/overdue indicators, who is holding the request, escalation history, expected next step. |
| P7 | **Every state designed** | Loading, empty (with next action), validation error (inline + summary), permission denied (explains why), stale step ("this step was already decided by X at T"), offline/retry. |
| P8 | **Accessibility** | WCAG 2.2 AA: contrast in light/dark and every built-in theme, focus order and visible focus, labelled controls, error association, target size ≥ 24px, no keyboard traps in modals/builders, reduced-motion respected. |
| P9 | **Mobile approval** | Approve/reject/return fully usable at 375px; attachments previewable; sticky decision bar. |
| P10 | **Trust & privacy cues** | Sensitive fields masked by default, download stamps explained, who-can-see-this indicators, session/device info. |
| P11 | **Consistency** | Flux components used consistently: page headers, buttons (one primary per view), destructive styling, table density, date formats, empty states. |
| P12 | **Admin safety** | Configuration screens show impact before saving (e.g. "affects new submissions only"), preview/simulate for workflows and forms, change history for settings. |

### 7.2 Screen inventory and order

Reviewed in priority order (requester and approver paths first, then administration):

1. Approvals inbox (`pages/approvals/inbox`) and approval decision modal
2. Submission detail (`pages/submissions/show`) — timeline, revisions diff, comments, attachments
3. Submission create / choose type / drafts (`pages/submissions/create`, `choose-type`)
4. Submissions index (`pages/submissions/index`) and global search
5. Dashboard (role-aware home) and announcements banner
6. Printable approval report (`reports/approval`)
7. Notifications centre and email templates (action-required emails)
8. Workflow templates + visual builder
9. Request types + form builder
10. Users, roles, departments, import
11. Audit trail, records, legal hold, evidence packs
12. Settings: branding, approvals, security, notification channels, system, backups, operations, updates
13. Auth screens (login, 2FA, passkeys, registration OTP) and Help centre

For each screen: screenshot desktop/mobile/dark → score P1–P12 → log `RV-UX-NN` findings → fix → re-screenshot → update Help.

### 7.3 Likely UX improvements to evaluate

Not committed until each is validated on the live screen:

- Sticky **decision panel** on submission detail with consequence-restating confirmation and required reason for reject/return.
- **Stale-step banner** when another approver has already acted.
- Configurable **attestation statement** on approve (organization setting, recorded in the action comment/metadata — not by mutating past actions).
- Inbox **saved views**, overdue/due-soon chips, and keyboard shortcuts (`j/k`, `a`, `r`) with a visible shortcut sheet.
- Unified **status badge** component used everywhere (list, detail, email, report).
- Timeline entries showing **"on behalf of"** for delegated actions and the timezone on every timestamp.
- Workflow builder **simulation** ("run this form through the workflow") before publishing, if not already complete.
- Breaking up the largest screens (detail page, request types, builders) into tabs/sections, which also helps the `ARC` track.

---

## 8. `FWD` — Remaining-plan review (Phases 10–15, Wave 5)

Goal: make sure v3.0.0 work starts from a sound base and the plan has no gaps that will force rework.

### 8.1 Pre-Phase-10 readiness review of current code

- [ ] Inventory every tenant-owned table, global singleton, cache key, storage path, scheduled command, queued job, notification, webhook, and sequence (from the `ARC` tenancy-smell list).
- [ ] Assess how the **System owner** (SH-OWN) maps to "platform operator" vs "organization owner" in P10-DATA-04 — the current model is site-wide and will conflict.
- [ ] Assess Spatie permission teams migration for **custom roles and role ceilings** (SH-RBAC).
- [ ] Assess settings/branding/themes/approval-ID formats/announcements, all currently global, for per-organization scoping.
- [ ] Assess backups/restore/promote and the in-app updater: these are self-hosted-only concepts that must be disabled or redesigned in `APP_MODE=saas`.
- [ ] Recommend a **"Phase 9.9 — Tenancy readiness"** set of refactors that can ship in self-hosted mode first (e.g. explicit context objects passed to jobs, scoped cache keys) to shrink Phase 10's risk.

### 8.2 Plan gap review

Check Phases 10–15 for missing or under-specified items, including:

- Per-tenant rate limits and queue fairness on the database queue driver (SaaS hosting may differ from shared hosting — record the decision in an ADR).
- Webhook raw-body signature verification behind Cloudflare/proxies (Phase 11).
- Tenant data export format and evidence-pack portability.
- Per-tenant encryption keys vs app key (Phase 15 mentions CMEK; earlier phases may need key scoping).
- Self-hosted vs SaaS code-path divergence strategy (feature flags vs mode checks) and test matrix for both modes.
- Upgrade path for existing self-hosted customers to SaaS (or explicit non-goal).

**Output:** amendments proposed as a PR to `IMPLEMENTATION_PLAN.md` plus new ADRs; no Phase 10 code.

---

## 9. Tooling

| Purpose | Tool |
|---|---|
| Tests / coverage | `php artisan test --parallel`, pcov + `scripts/coverage-gate.php` |
| Static analysis | Larastan level 7 (baseline burn-down), Rector dry-run |
| Style | Pint |
| Dependencies | `composer audit`, `npm audit` |
| Queries | `preventLazyLoading`, `QueryBudgetTest`, `EXPLAIN` on MySQL, Debugbar locally (dev dependency only) |
| DB parity | Local MySQL 8 and MariaDB 10.6 runs |
| Security | Route snapshot walk, manual review, targeted feature tests; optional OWASP ZAP baseline scan against a local instance |
| UX / a11y | Built-in browser screenshots at 1280/375, light/dark; axe; Lighthouse |
| Parallel review | Read-only subagents per track; fixes applied sequentially |

---

## 10. Deliverables and exit criteria

| Deliverable | Exit criterion |
|---|---|
| `docs/review/FINDINGS.md` | Every lead and finding has a status; no S1/S2 open |
| Patch releases v2.8.7+ | S1/S2 fixes shipped with regression tests |
| v2.9.0 | Architecture refactors + UI/UX improvements, Help updated, CHANGELOG |
| Coverage | ≥ 80% `app/`; `WorkflowEngine` 100% (or documented, agreed target) |
| PHPStan | Baseline reduced (target ≥ 50%); no new entries |
| Performance | Query budgets on all key pages; heavy queries indexed; results in `docs/PERFORMANCE.md` |
| Accessibility | Primary flow passes WCAG 2.2 AA checks at desktop and mobile |
| DB parity | Suite green on SQLite, MySQL 8, MariaDB 10.6 |
| Forward plan | IMPLEMENTATION_PLAN amendments + ADRs merged; Phase 9.9 tasks listed |
| Final report | Summary of findings by track/severity, what was fixed, what was deferred and why |

---

## 11. Decisions

1. **Release cadence — Decided (2026-10-12):** fixes ship as patch releases from **v2.8.7** onwards.
2. **UI/UX scope — Decided (2026-10-12):** improve within the current **Flux** design system; no visual redesign.
3. **Large-component refactors — Decided (2026-10-16):** split `submissions/show`, `request-types`, `workflow-templates`, and `workflow-builder` into smaller components with business rules moved to Actions/Services, in Wave 3, after each file's Wave 1/2 fixes have landed.
4. **WorkflowEngine coverage target — Decided (2026-10-16):** keep the CI gate at 97% as the floor; aim for 100% during Wave 1 engine work and raise the gate only when reached.
5. **MariaDB support — Decided (2026-10-12):** MariaDB 10.6 stays supported; CI job added in Wave 0.
