# ApproveDesk — Product Implementation & Execution Plan

This is the canonical execution plan for evolving ApproveDesk from the completed v2.0.0 single-organization product into a competitive approval platform and, ultimately, a multi-tenant SaaS with Paystack and Flutterwave billing.

It is written so that a new engineer or AI agent can resume work without relying on prior conversation history. Repository-wide engineering rules and workflow invariants remain in [AGENTS.md](AGENTS.md). Deployment, authentication, and API contracts remain in `docs/`.

## 1. Product direction

ApproveDesk will become:

> A deploy-anywhere, audit-ready approval platform that lets teams configure requests and approval workflows without code, make decisions quickly from any device, and retain defensible evidence of every action.

The roadmap deliberately follows this order:

1. Improve the non-enterprise product and everyday user experience.
2. Add configurable forms, visual workflows, collaboration, integrations, and governance.
3. Stabilize those capabilities before introducing tenancy and billing.
4. Convert the stable product into a SaaS using Paystack and Flutterwave.
5. Add enterprise capabilities and native mobile applications only after the web SaaS is stable.

Native mobile and enterprise work must not be pulled forward merely because a customer asks for an isolated feature. Responsive mobile web is required earlier; a native mobile app is not.

## 2. Completed baseline — v2.0.0

Phases 0–4 from the original plan are complete. The current baseline includes:

- Laravel 13, PHP 8.3+, Livewire 4, Alpine.js, Tailwind CSS 4, and Flux UI.
- Installer-first deployment for cPanel/Plesk plus container deployment.
- Roles, departments, policies, branded authentication, passkeys, 2FA, Google/Microsoft OAuth.
- Draft submissions, private attachments, comments, signed previews/downloads, and rich text.
- Sequential, parallel, quorum, conditional, and automatic workflow steps.
- Delegation, return for revision, information requests, escalation, SLA actions, and digests.
- Append-only workflow actions, transactional state transitions, and stale-action protection.
- Email approval links, analytics/export, REST API, personal tokens, and outbound webhooks.

The append-only audit log and `WorkflowEngine` invariants in [AGENTS.md](AGENTS.md) remain non-negotiable through every future phase.

## 3. Execution rules for every future phase

### 3.1 Phase independence

Every phase must end with a deployable application. Partially built features remain behind disabled feature flags and must not break existing routes, migrations, queues, APIs, or workflows.

At every phase exit:

```bash
composer install
npm ci
php artisan migrate --force
php artisan test --parallel
./vendor/bin/pint --dirty
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Additionally:

- CI must pass on SQLite.
- A MySQL/MariaDB parity run is required before the release tag.
- Existing workflow instances created in earlier versions must still open and complete.
- The installer must work from a clean database.
- The upgrade path must work against a copy of the previous phase's database.
- `/up`, login, submit, approve, reject, return, and attachment download smoke checks must pass.
- Accessibility checks must cover the primary flow at desktop and narrow mobile-web widths.
- `CHANGELOG.md`, API docs, deployment docs, and this plan's status table must be updated.

### 3.2 Task definition of done

A task is complete only when it has:

1. An additive migration or explicit statement that no schema change is required.
2. Domain code in Actions/Services rather than controllers or Livewire components.
3. Policy authorization and tenant scoping where applicable.
4. Unit/feature tests for success, denial, invalid input, and race/idempotency risks.
5. UI states for loading, empty, success, validation error, authorization denial, and retry where applicable.
6. Documentation for operators and future contributors, **including the in-app Help centre**. Every new or changed product feature needs a definition and a detailed explanation in `docs/kb/` and `/help` before the phase can be marked complete. See section 3.4.
7. No unresolved TODO that is necessary for the task to function.

### 3.3 Safe delivery pattern

- Use short-lived branches named `feature/<phase>-<task>`.
- One task ID or one tightly coupled task group per pull request.
- Never edit a released migration; add a new migration and a tested backfill.
- Put irreversible data transitions behind a preflight command and database backup instruction.
- Introduce new engines through interfaces so old implementations can remain available during migration.
- Feature flags must have an owner, removal phase, default value, and test coverage.
- Record material architecture decisions in `docs/adr/NNNN-title.md`.

### 3.4 Administrator knowledge base

The in-app Help centre (`/help`) and Markdown source in `docs/kb/` are the product knowledge base. They explain how to use the application. They do not cover installation, hosting, Docker, CI/CD, or `.env` files.

This is a phase exit requirement. A phase in this plan is not complete until Help has been updated for every new or changed feature. Articles must define the feature, explain when to use it, explain how the live screens work, and state what not to do.

The Help entry stays in the sidebar footer as a compact menu, with a matching icon in the mobile header. It must not add another item under Administration or crowd page headers. Administration screens may include a life-ring icon that opens the matching article.

After each completed phase:

1. Update `docs/kb/` for new or changed screens, with definitions and detailed explanations.
2. Refresh `docs/kb/definitions.md` for any new product term.
3. Register those articles in `App\Support\HelpCatalogue`.
4. Confirm Help as an administrator and as a requester.
5. Commit the Markdown, catalogue, Help UI, and this plan together.

**Administrator knowledge base through Phase 8.1 — Complete (2026-08-20):** feature definitions (including workflow version, builder, graph validation, selection rule, assignee resolver, group completion, business calendar / office hours, custom outcome, assignee picker, follower, mention, reminder, out of office, expected next step, and estimated due time), getting around, requests, approvals, collaboration, statuses, account settings (including out of office), users and roles (including E.164 phone numbers), departments, workflow templates (including upgrade guarantees and the searchable assignee picker), request types (including workflow assignment and selection rules), analytics, webhooks, organization settings (including office hours and reminder quiet hours), and the audit trail.

### 3.5 Request types own workflows

Locked product decision for work after Phase 6. It does not reopen Phases 0–6.

- A request type (the published form) selects the workflow template used for new submissions of that type.
- Any number of named templates may be active at once. Exclusive ownership of Document, Note, Memo, or Request by an active template is not the routing model going forward.
- Exactly one default template remains, as fallback for General Request and for any type with no assignment.
- Document, Note, Memo, and Request stay as General Request fields where they already exist. They are not the routing key.
- In-flight workflow instances keep the template they started with. Changing an assignment or the default affects only new submissions.
- Requesters pick a form. They do not pick a workflow.

Phase 6A implements this assignment. Phase 7 keeps its original scope: versioned templates, visual builder, and later selection rules on form values. Phase 7 must not revert exclusive submission-type locking.

## 4. Release map and status

| Phase | Target | Theme | Status |
|---|---|---|---|
| 0–4 | v2.0.0 | Existing workflow platform | Complete |
| 5 | v2.1.0 | Non-enterprise UX, accessibility, and approver productivity | Complete |
| 6 | v2.2.0 | Configurable request types and form builder | In progress — 6.1–6.2 complete |
| 6A | v2.2.1 | Request-type workflow assignment | Complete |
| 7 | v2.3.0 | Visual workflows, versioning, and dynamic assignment | Complete |
| 7A | v2.3.1 | Assignee picker UX and user phone numbers | Complete |
| 7B | v2.3.2 | Organization working / office hours | Complete |
| 8 | v2.4.0 | Collaboration, notifications, and integration hub | In progress — 8.1 complete |
| 9 | v2.5.0 | Governance, audit evidence, security, and reliability | Not started |
| 10 | v3.0.0 | SaaS multi-tenancy foundation | Not started |
| 11 | v3.1.0 | Paystack and Flutterwave subscriptions | Not started |
| 12 | v3.2.0 | SaaS onboarding, entitlements, and commercial operations | Not started |
| 13 | v3.3.0 | Intelligence and process optimization | Not started |
| 14 | v4.0.0 | SaaS stabilization and general availability | Not started |
| 15 | v5.0.0 | Enterprise capabilities and native mobile apps | Blocked until Phase 14 is stable |

---

## Phase 5 — Non-enterprise UX, accessibility, and approver productivity

**Goal:** make the existing product fast, understandable, responsive, and pleasant for ordinary requesters, approvers, and administrators without changing core workflow semantics.

### 5.1 Design-system foundation — Complete (2026-08-18)

- **P5-DS-01:** Document color, spacing, typography, radius, elevation, status, and focus tokens in `resources/css/app.css` and a design-system reference page available only in local/testing environments.
- **P5-DS-02:** Raise dark/light theme contrast to WCAG 2.2 AA targets for normal text, controls, focus indicators, badges, timestamps, and disabled states.
- **P5-DS-03:** Standardize page headers, primary/secondary/destructive actions, cards, tables, modals, alerts, empty states, skeletons, toasts, and validation summaries.
- **P5-DS-04:** Remove framework repository/documentation links from production navigation; add Help, notifications, profile, and context-appropriate navigation.
- **P5-DS-05:** Add responsive sidebar/drawer, responsive tables-to-cards, 200% zoom support, and touch targets of at least 44px for core actions.

### 5.2 Role-aware home and navigation — Complete (2026-08-18)

- **P5-NAV-01 — Complete:** Global search covers reference, title, requester, status, and the current metadata index; every result is authorized through `SubmissionPolicy` before display.
- **P5-NAV-02 — Complete:** Requester, approver, administrator, mixed-role, and viewer home modes put decisions, drafts/responses, or organization controls first as appropriate.
- **P5-NAV-03 — Complete:** The notification centre includes live unread counts, individual/all read controls, policy-safe deep links, and recorded preference-aware delivery labels.
- **P5-NAV-04 — Complete:** Reusable breadcrumbs and consistent back-navigation cover submissions, approvals, notifications, and all administration editors.

### 5.3 Approver workbench — Complete (2026-08-18)

- **P5-ACT-01 — Complete:** Replaced the three static inbox columns with one prioritized queue and saved views: Needs my decision, Due soon, Overdue, Waiting on others, and Completed.
- **P5-ACT-02 — Complete:** Added search and filters for status, deadline, priority, request type, department, requester, and delegation.
- **P5-ACT-03 — Complete:** Added compact decision cards with requester, key fields, SLA countdown, current step, attachment count, and last activity.
- **P5-ACT-04 — Complete:** Added an inline preview drawer and a sticky decision bar on the submission detail page.
- **P5-ACT-05 — Complete:** Added keyboard shortcuts for next/previous request and approve/reject modal opening; destructive decisions still require confirmation and required comments.
- **P5-ACT-06 — Complete:** Added guarded batch approval for homogeneous, eligible requests. Rejections, returns, escalations, and information requests remain individual actions.

### 5.4 Requester and administrator experience — Complete (2026-08-18)

- **P5-REQ-01 — Complete:** Added My requests saved views for Drafts, In progress, Needs my response, Completed, and Withdrawn, with requester-scoped counts and authorization-safe queries.
- **P5-REQ-02 — Complete:** Made autosave state visible with Saving, Saved at, Offline/failed, and Retry indicators.
- **P5-REQ-03 — Complete:** Added view-specific empty states with clear explanations and primary next actions.
- **P5-ADM-01 — Complete:** Upgraded dashboards with awaiting-me, overdue, median cycle time, returned rate, SLA compliance, bottleneck step, and delegation-aware workload-by-approver metrics.
- **P5-ADM-02 — Complete:** Added accessible chart summaries and tabular alternatives for daily activity, bottlenecks, and approver workload.

### 5.5 Tests and exit gate — Complete (2026-08-19)

- **P5-TEST-01 — Complete:** Livewire feature tests cover saved views, authorization, filtering, batch eligibility, and notification state.
- **P5-TEST-02 — Complete:** Dusk covers requester draft/submit, approver decision, keyboard focus order, modal validation, and responsive navigation.
- **P5-TEST-03 — Complete:** Automated axe scans cover login, dashboard, inbox, create request, and submission detail with zero critical/serious violations.
- **P5-TEST-04 — Complete:** Query-count assertions guard dashboard, inbox, and submission-list budgets.
- **P5-TEST-05 — Complete:** Desktop widths 1280/1440 and mobile-web widths 360/390/768 are verified without horizontal overflow and with correct navigation behavior.

**Phase 5 demo:** a requester creates and finds a draft on mobile web; an approver opens a prioritized queue, previews the request, and approves it without losing context; an administrator sees overdue work and bottlenecks.

---

## Phase 6 — Configurable request types and form builder

**Goal:** allow non-technical administrators to define reusable request forms instead of forcing every submission into the fixed type/title/body schema.

### 6.1 Versioned request schema — Complete (2026-08-19)

- **P6-DATA-01 — Complete:** Added `request_types`, `request_type_versions`, `request_field_definitions`, and `submission_field_values`, with model relationships and factories for schema authoring.
- **P6-DATA-02 — Complete:** Typed values use dedicated nullable string, long-text, decimal, date/time, boolean, user, department, and JSON columns with portable scalar indexes and version-consistency validation.
- **P6-DATA-03 — Complete:** Published versions and their field definitions reject mutation or deletion; publication records a canonical schema hash, and every submission references one exact non-null version.
- **P6-DATA-04 — Complete:** The migration creates the built-in General Request v1, backfills legacy values, and preserves submission references and append-only workflow history; new and edited fixed-form requests keep typed values synchronized.

### 6.2 Form builder — Complete (2026-08-19)

- **P6-FORM-01 — Complete:** Administrators can build forms with short, long, rich-text, number, currency, date, date-time, choice, checkbox, user, department, URL, email, file, and calculated read-only fields.
- **P6-FORM-02 — Complete:** Field configuration supports sections, instructions, required state, placeholders, defaults, numeric/text limits, allowed file types, and help text.
- **P6-FORM-03 — Complete:** Visibility and conditional-required rules use earlier-field references and allow-listed operators only; calculated fields are restricted to sums of earlier numeric fields.
- **P6-FORM-04 — Complete:** The builder supports drag ordering and move controls, live preview, duplication, draft/publish/archive lifecycle, and request-type cloning while preserving published-version immutability.
- **P6-FORM-05 — Complete:** A schema compiler produces authoritative server-side Laravel validation and typed value persistence from the exact published version, while incomplete drafts remain saveable.
- **P6-FORM-06 — Complete:** Published schemas render as accessible responsive requester forms with review and print views, plus CSV import/export for choice options.
- **P6-FORM-07 — Complete (2026-08-19):** Field keys are generated by the builder from the label, hidden from administrators, unique within a version, and kept stable for existing fields. Visibility, required-when, and calculated-field pickers show labels while storing keys.

### 6.3 Search and reporting — Complete (2026-08-19)

- **P6-SRCH-01 — Complete:** Request fields can be marked searchable, filterable, reportable, sensitive, and/or approver-summary; the flags are versioned in published schema hashes and remain immutable with the published version.
- **P6-SRCH-02 — Complete:** Global and submission-list search include configured typed fields, analytics can filter by request type and summarize reportable values, and sensitive values are excluded from unauthorized search, reports, list configuration, and submission details.
- **P6-SRCH-03 — Complete:** Submission lists support request-type-specific field filters and reportable columns, with named per-user saved views that preserve filters and column selections.

### 6.4 Tests and exit gate — Complete (2026-08-19)

- **P6-TEST-01 — Complete:** Schema compiler unit tests cover every field type, typed persistence column, defaults, limits, choice constraints, conditional operators, required rules, calculations, invalid references, and draft validation.
- **P6-TEST-02 — Complete:** Feature tests prove published schema immutability and render an old submission from its original version after a replacement version is published.
- **P6-TEST-03 — Complete:** Authorization tests prove sensitive values are visible to authorized owners and administrators but excluded from approver search, submission details, list configuration, and unauthorized analytics.
- **P6-TEST-04 — Complete:** The migration test loads a checked-in ApproveDesk v2.1 SQL database fixture and verifies references, typed values, and append-only workflow history survive the upgrade through the Phase 6 migrations.
- **P6-TEST-05 — Complete:** Dusk covers an administrator building and publishing a custom form and a requester completing, saving, reopening, reviewing, and submitting it.

**Phase 6 demo:** an administrator builds and publishes a Leave Request form; a requester completes it; search/report filters use its typed fields; editing the form creates a new version without altering the old request.

The administrator knowledge base in `docs/kb/` and `/help` covers every shipped product area through this phase. Later phases must extend those articles before they are marked complete.

---

## Phase 6A — Request-type workflow assignment — Complete (2026-08-19)

**Goal:** let administrators create as many workflow templates as they need, and attach each request type to the template that should run for new submissions. This is the industry-standard binding (the form owns the process). It does not start the Phase 7 visual builder, workflow versioning, or form-value selection rules.

This phase is additive. Phases 0–6 stay complete as written. Phase 7 task IDs and scope stay unchanged.

### 6A.1 Assignment model

- **P6A-DATA-01 — Complete:** Add a nullable `workflow_template_id` on `request_types`, with a foreign key to `workflow_templates`. No existing columns are removed.
- **P6A-DATA-02 — Complete:** Backfill each existing request type to the template `WorkflowEngine` would resolve today (type-specific match, else the default template). Historical `workflow_instances.template_id` values must not change.
- **P6A-DATA-03 — Complete:** Keep Document, Note, Memo, and Request on General Request for compatibility. Stop using those four values as the exclusive routing key.

### 6A.2 Routing and uniqueness

- **P6A-RULE-01 — Complete:** Remove the active-template guard that rejects a second template sharing a submission type. Many active templates are valid.
- **P6A-RULE-02 — Complete:** Keep uniqueness of the default template: at most one `is_default = true` active template.
- **P6A-RULE-03 — Complete:** `WorkflowEngine::resolveTemplate()` uses, in order: the submission's request type assignment if that template is active; else the active default template; else the existing no-template error. Do not re-introduce submission-type JSON matching as the primary resolver.
- **P6A-RULE-04 — Complete:** Changing a request type’s template or the default template must not rewrite running or completed instances.

### 6A.3 Administration UI

- **P6A-UI-01 — Complete:** On the request type screen, administrators select the workflow template for that type and see which template will apply to the next new submission.
- **P6A-UI-02 — Complete:** The workflow template screen allows multiple active templates without requiring unused submission types. Default remains a single fallback. Submission-type checkboxes, if still shown, are informational only and must not enforce exclusivity.
- **P6A-UI-03 — Complete:** Requesters continue to choose a request type only. They are not asked to choose a workflow.

### 6A.4 Tests, docs, and exit gate

- **P6A-TEST-01 — Complete:** Two active templates may exist without conflict. Two request types may share one template. A type with no assignment uses the default.
- **P6A-TEST-02 — Complete:** Submitting Leave Request (or any custom type) starts the assigned template; a later assignment change does not alter an in-flight instance.
- **P6A-TEST-03 — Complete:** Upgrade test: a copy of the Phase 6 database still opens, submits, and completes, and backfilled assignments match pre-6A resolution.
- **P6A-DOC-01 — Complete:** Update `docs/kb/workflow-templates.md` and `docs/kb/request-types.md`, then the in-app Help catalogue, per section 3.4.

**Phase 6A demo:** an administrator publishes two templates and assigns Leave Request to one and Purchase Request to the other; both remain active; General Request still uses the default; an in-progress Leave Request keeps its original chain after the assignment is changed.

**Out of scope (remain in Phase 7):** immutable workflow versions, visual canvas, simulation, assignee resolvers, business calendars, custom outcomes, and selection rules driven by form field values (`P7-RTE-05`).

---

## Phase 7 — Visual workflows, versioning, and dynamic assignment

**Goal:** expose the existing workflow power through a safe visual builder and add the routing capabilities expected from modern no-code approval products.

Phase 6A is a prerequisite for routing but is not part of this phase. Do not renumber or rewrite the tasks below. `P7-RTE-05` adds value-based selection on top of request-type assignment; it does not replace Phase 6A and must not restore exclusive Document/Note/Memo/Request template locking.

### 7.1 Workflow version model — Complete (2026-08-19)

- **P7-DATA-01 — Complete:** Introduce immutable `workflow_template_versions`; instances reference a version, not a mutable template.
- **P7-DATA-02 — Complete:** Backfill existing templates and instances while preserving step/action IDs required by historical audit entries.
- **P7-DATA-03 — Complete:** Separate workflow lifecycle into Draft, Published, Retired, and Archived.
- **P7-DATA-04 — Complete:** Record author, publication time, change summary, and a canonical schema hash for every version.

### 7.2 Visual builder — Complete (2026-08-19)

- **P7-BLD-01 — Complete:** Add a canvas/list dual view with start, approval, condition, automatic action, notification, and end nodes.
- **P7-BLD-02 — Complete:** Support connect, reorder, duplicate, delete, zoom, fit-to-screen, undo/redo during the current editing session, and keyboard-accessible list editing.
- **P7-BLD-03 — Complete:** Validate unreachable nodes, cycles, dead ends, impossible quorum, missing assignees, invalid branches, and unsafe timeout behavior before publish.
- **P7-BLD-04 — Complete:** Add preview and simulation using sample request data. Simulation must be read-only and produce an explainable route trace.
- **P7-BLD-05 — Complete:** Add clone, compare versions, restore-as-new-draft, and publish confirmation.

### 7.3 Dynamic routing and assignment — Complete (2026-08-19)

- **P7-RTE-01 — Complete:** Add assignee resolvers for named user, role, department, requester's manager, department manager, user selected in a form field, and external resolver interface.
- **P7-RTE-02 — Complete:** Define group completion as first response, all, or quorum N-of-M.
- **P7-RTE-03 — Complete:** Add business-calendar-aware deadlines while retaining simple elapsed-hour deadlines.
- **P7-RTE-04 — Complete:** Add custom outcomes such as Approved with conditions while mapping each outcome to explicit engine behavior.
- **P7-RTE-05 — Complete:** Add workflow selection rules by request type and form values, with deterministic precedence and an explanation visible to administrators.

### 7.4 Tests and exit gate — Complete (2026-08-19)

- **P7-TEST-01 — Complete:** `WorkflowEngine` coverage remains required; engine branches for outcomes, unresolved assignees, stale steps, timeouts, resume, and missing templates are covered.
- **P7-TEST-02 — Complete:** Contract tests for every assignee resolver and completion rule.
- **P7-TEST-03 — Complete:** Property-style tests for generated valid/invalid graphs and deterministic route selection.
- **P7-TEST-04 — Complete:** Concurrency tests for first-response and parallel group decisions and publication races.
- **P7-TEST-05 — Complete:** Upgrade tests proving a running v2.1 workflow completes against its original version after later publishes.

**Phase 7 demo — Complete (2026-08-19):** an administrator visually builds, validates, simulates, and publishes a conditional expense workflow; a later edit produces a second version while requests on version one continue correctly.

---

## Phase 7A — Assignee picker UX and user phone numbers — Complete (2026-08-20)

**Goal:** let administrators find assignees in large directories without loading the whole roster, and store an international phone number on each user for later SMS and WhatsApp delivery. This does not change routing, versions, or resolver semantics.

This phase is additive. Phases 0–7 stay complete as written. Phase 8 task IDs and scope stay unchanged. Do not reopen exclusive Document/Note/Memo/Request locking.

### 7A.1 Searchable assignee picker

- **P7A-UI-01 — Complete:** Replace the full checkbox roster on the workflow builder and template form with a searchable picker. Selected people, roles, or departments appear as chips. Search runs on the server after a short debounce, requires at least two characters, and returns a capped result set. The Livewire page must not load every active user on open.
- **P7A-UI-02 — Complete:** Keep `assignee_ids` as the stored contract. Search is only how an administrator finds a record. Published graphs stay frozen.
- **P7A-UI-03 — Complete:** The line-manager control on the user form uses the same search pattern instead of listing every user.

### 7A.2 User phone numbers

- **P7A-DATA-01 — Complete:** Add a nullable unique `users.phone` column. Values are stored in E.164 (`+` and 8–15 digits, no spaces). Empty is allowed.
- **P7A-UI-04 — Complete:** Administrators can set the phone number on create and edit user. Invalid local formats are rejected.

### 7A.3 Tests, docs, and exit gate

- **P7A-TEST-01 — Complete:** Opening the builder does not render the full user directory. Typing two or more characters returns matching assignees; adding and removing a chip updates `assignee_ids`.
- **P7A-TEST-02 — Complete:** User create/update accepts a valid E.164 number and rejects numbers that are not international.
- **P7A-DOC-01 — Complete:** Update Help for workflow templates (picker) and users (phone), per section 3.4.

**Phase 7A demo:** an administrator types part of an approver's name in the builder, pins them as a chip, and saves; the published step still stores only that user's ID.

**Out of scope (remain in Phase 8):** SMS and WhatsApp delivery, quiet hours, and channel templates. The phone column is storage only.

---

## Phase 7B — Organization working / office hours — Complete (2026-08-20)

**Goal:** make "Business hours" on workflow steps mean organization office hours as well as working days and holidays, without changing Elapsed-hour deadlines or snapshotting due times onto instances.

This phase is additive. Phases 0–7 stay complete as written. Phase 7A stays complete as written. Phase 6 remains in progress with its remaining tasks unchanged. Phase 8 task IDs and scope stay unchanged. Do not reopen exclusive Document/Note/Memo/Request locking. Do not rewrite P7-RTE-03.

Design: [docs/design/7b-organization-working-hours.md](docs/design/7b-organization-working-hours.md).

### 7B.1 Business calendar office hours

- **P7B-CAL-01 — Complete:** Extend `App\Services\Workflow\BusinessCalendar` so an instant counts toward a Business-hour SLA only when the weekday is in `calendar.working_days`, the date is not in `calendar.holidays`, and (when office hours are set) the time of day falls in that weekday's half-open `[start, end)` window. Keep the existing hour-tick `addHour()` loop when `calendar.working_hours` is unset or empty. When a window is set, add remaining time with a window-skipping algorithm over integer seconds (`hours * 3600`); `take > 0` or jump strictly forward. Keep the public `int $hours` API. Include `timeout_calendar` in `AnalyticsService`'s constrained `currentStep` eager load so analytics overdue uses the Business clock. `deadlineAt()` must not throw through list mappers.

- **P7B-CAL-02 — Complete:** Persist JSON `calendar.working_hours` (`{"start":"HH:MM","end":"HH:MM","by_day":{...}}`) through `UpdateApprovalSettingsAction` and `Setting::put`. No new table and no migration. Do not backfill 09:00–17:00. Honor `by_day` in the calendar if present; the 7B UI does not edit it. Merging default start/end on save must not wipe an existing `by_day`. Hours are in `config('app.timezone')` (currently UTC). Require `start < end` (no overnight shifts). Action contract: `null`+`null` leave the key unchanged; empty+empty clear it.

### 7B.2 Approval settings and template copy

- **P7B-UI-01 — Complete:** On Approval settings, add office-hours start and end time inputs. Empty means all hours on working days. Copy must state that this applies only to Business-hour steps, that Elapsed hours ignore it, that a 24-hour working day is empty fields (not 00:00–23:59), and that changing hours immediately affects in-flight Business deadlines. Label times with the application timezone. Normalize `H:i:s` before validate.

- **P7B-UI-02 — Complete:** Relabel/help-text only on the workflow builder and template form: Business hours use organization working days, office hours, and holidays. Do not add a third `TimeoutCalendar` case and do not change published-graph schema.

### 7B.3 Tests, docs, and exit gate

- **P7B-TEST-01 — Complete:** Unit-test `BusinessCalendar` for hour-tick 24h landings (weekend start, holiday midnight, unset canary time 09:00:00), window inside/outside, `09:00:30`+8h → next working 09:00:30, `16:59:30`+1h → 09:59:30, Friday `by_day` close and spill, holiday, overnight rejected, and safety-cap throw. `test_business_calendar_skips_weekend` must still pass with unset hours.

- **P7B-TEST-02 — Complete:** Feature-test that saving office hours on Approval settings changes a Business deadline and does not change an Elapsed deadline; lifetime-only save does not wipe hours; Livewire validation: start < end, HH:MM, both empty allowed, `09:00:00` accepted. `ProcessEscalation` continues after an unconsumable Business instance. Analytics Business 24h over a weekend is not overdue. `WorkflowEngine` coverage remains 100%.

- **P7B-DOC-01 — Complete:** Record ADR `docs/adr/0001-organization-working-hours.md`. Update `CHANGELOG.md`, `docs/kb/organization-settings.md`, `docs/kb/workflow-templates.md`, `docs/kb/definitions.md` (Business calendar), `docs/kb/README.md` index line, §3.4 Help sentence, and `App\Support\HelpCatalogue` per section 3.4.

**Phase 7B demo:** an administrator leaves office hours empty and a Thursday 09:00 + 24 Business-hour step still skips the weekend as today; they then save 09:00–17:00 and a Monday 09:00 + 8 Business-hour step falls due at 17:00 the same day, while an Elapsed-hour step is unchanged.

**Out of scope (remain in later phases):** per-user hours; P8-COL-03 nudge quiet hours; P8-COL-04 out-of-office schedules; P8-COL-05 estimated due time (may consume this calendar later); P8-NOT-04 notification quiet hours; P10-TEN-01 organization timezone; overnight shifts; named multiple calendars; changing email-link expiry to business hours.

---

## Phase 8 — Collaboration, notifications, and integration hub

**Goal:** keep work moving without requiring every participant to remain inside ApproveDesk and provide useful integrations without custom code.

### 8.1 Collaboration — Complete (2026-08-20)

- **P8-COL-01 — Complete:** Add watchers/followers with explicit view permission, notification preferences, and self-unfollow.
- **P8-COL-02 — Complete:** Add @mentions, mention notifications, thread replies, edit window, and append-only edit history.
- **P8-COL-03 — Complete:** Add manual reminders/nudges with rate limits, audit records, and quiet-hour handling.
- **P8-COL-04 — Complete:** Add out-of-office schedules, future delegation, fallback approvers, and conflict detection.
- **P8-COL-05 — Complete:** Add requester-visible expected next step and estimated due time.

### 8.2 Notification delivery

- **P8-NOT-01:** Use a channel interface for database, email, web push, Microsoft Teams, Slack, SMS, and WhatsApp.
- **P8-NOT-02:** Implement Teams and Slack interactive notifications with signed action state and the same stale-step policy checks as email approvals.
- **P8-NOT-03:** Add installable PWA shell, web-push notifications, mobile-web attachment capture, and safe offline draft editing. Approval decisions always require an online server check.
- **P8-NOT-04:** Add delivery receipts, retry visibility, per-tenant templates, quiet hours, and escalation rules for undelivered critical notifications.

### 8.3 Integration hub

- **P8-INT-01:** Define connector contracts for triggers, actions, authentication, health checks, rate limits, and redacted logs.
- **P8-INT-02:** Ship initial connectors for Microsoft Teams, Slack, Google Drive, SharePoint/OneDrive, Gmail/Outlook, and generic HTTP.
- **P8-INT-03:** Add post-approval actions: upload file, send email, create/update external record, or invoke a signed webhook.
- **P8-INT-04:** Add connector credential encryption, rotation, least-privilege instructions, test connection, retry/dead-letter queues, and replay.
- **P8-INT-05:** Extend REST API with request-type schemas, cursor pagination, idempotency keys, organization-ready scopes, and OpenAPI documentation.

### 8.4 Tests and exit gate

- Connector contract test suite with faked providers; no live service dependency in CI.
- Webhook/action idempotency and replay tests.
- Signed-action tampering and stale-step tests for Teams/Slack/email.
- Offline draft conflict tests and online-only decision enforcement.
- Queue failure and retry tests using the database driver.

**Phase 8 demo:** a request starts from a custom form, notifies an approver in Teams or Slack, receives a policy-checked decision, uploads the approved document to Drive/SharePoint, and records every delivery/action.

---

## Phase 9 — Governance, audit evidence, security, and reliability

**Goal:** make the mature non-enterprise product defensible, recoverable, and operationally trustworthy before introducing multiple paying tenants.

### 9.1 Audit and records

- **P9-AUD-01:** Generate a per-request evidence pack containing request/version data, document hashes, workflow version, assignments, actions, comments, delegations, notifications, and timestamps.
- **P9-AUD-02:** Sign evidence-pack manifests and add a verification command/page that detects altered files or records.
- **P9-AUD-03:** Add auditor access with read-only, time-bounded scopes and export logs.
- **P9-AUD-04:** Add configurable retention schedules, legal holds, disposition review, and storage lifecycle jobs.
- **P9-AUD-05:** Add request and attachment version history with before/after summaries and immutable provenance.

### 9.2 Security and privacy

- **P9-SEC-01:** Perform formal threat models for authentication, files, integrations, workflow actions, exports, and upcoming SaaS tenancy.
- **P9-SEC-02:** Add per-field sensitivity, export redaction, download watermarking option, and audit of sensitive-field access.
- **P9-SEC-03:** Add secret rotation procedures, CSP, security headers, session/device management, and suspicious-login notifications.
- **P9-SEC-04:** Add rate-limit profiles for login, API, approval links, exports, webhooks, and integrations.
- **P9-SEC-05:** Add self-service user data export and administrator-assisted deletion/anonymization that preserves required audit evidence.

### 9.3 Reliability and operations

- **P9-OPS-01:** Add structured logs, correlation IDs, queue/job metrics, webhook/connector health, and scheduler heartbeat.
- **P9-OPS-02:** Add backup/restore verification tooling and a documented restore drill.
- **P9-OPS-03:** Add dead-letter management, safe replay, stuck-workflow diagnostics, and reconciliation commands.
- **P9-OPS-04:** Establish performance budgets and load tests for 100k submissions, 1m actions, large attachments, and high approver fan-out.
- **P9-OPS-05:** Add zero-downtime-compatible migrations and release rollback runbooks.

**Phase 9 exit:** security review findings are resolved or explicitly accepted; restore drill succeeds; evidence pack verification succeeds; load targets meet documented budgets; the application is declared ready for tenant isolation work.

---

## Phase 10 — SaaS multi-tenancy foundation

**Goal:** convert the stable single-organization system into a shared-database SaaS while preserving a supported self-hosted mode.

### 10.1 Architecture decisions

- **P10-ADR-01:** Record the shared-database tenancy decision, tenant-resolution rules, platform/tenant role separation, domain strategy, and self-hosted compatibility.
- **P10-ADR-02:** Add `APP_MODE=self_hosted|saas`. Billing and platform administration remain disabled in self-hosted mode.
- **P10-ADR-03:** Use explicit organization context and fail closed when context is absent. Queue jobs, notifications, commands, exports, and scheduled tasks must serialize organization identity.

### 10.2 Tenant data model

- **P10-DATA-01:** Add `organizations`, `organization_domains`, `organization_memberships`, and `organization_invitations`.
- **P10-DATA-02:** Add `organization_id` to every tenant-owned table through nullable migrations, deterministic backfill, validation command, then non-null constraints.
- **P10-DATA-03:** Enable organization-aware roles/permissions using Spatie's team capability or an ADR-approved equivalent.
- **P10-DATA-04:** Separate platform roles from organization roles. A platform operator never silently inherits tenant data access.
- **P10-DATA-05:** Convert the existing installation into one default organization without changing user credentials or workflow history.

### 10.3 Tenant lifecycle

- **P10-TEN-01:** Add organization creation, slug, logo/branding, timezone, locale, base currency, and owner transfer.
- **P10-TEN-02:** Add invitation, acceptance, resend, expiry, membership suspension, leave organization, and organization switcher.
- **P10-TEN-03:** Add tenant-aware URLs and API scopes. Legacy URLs redirect to the user's default organization during a deprecation window.
- **P10-TEN-04:** Add organization export, closure request, grace period, and delayed purge workflow.
- **P10-TEN-05:** Scope storage paths, cache keys, locks, reference numbers, notification channels, webhooks, API tokens, and search indexes by organization.

### 10.4 Isolation tests and exit gate

- Automated isolation tests for every tenant-owned model, route, policy, job, notification, export, file, cache key, API endpoint, and search query.
- Tests deliberately reuse identical numeric IDs and references across tenants.
- Static check prevents new tenant-owned models from being added without isolation tests.
- Cross-tenant signed URLs, queued jobs, webhook replay, and impersonation attempts must fail.
- Upgrade test migrates a production-like single-organization database into the default organization.

**Phase 10 demo:** two organizations use the same deployment with overlapping reference numbers and users; neither can discover, access, export, receive, or process the other's data.

---

## Phase 11 — Paystack and Flutterwave subscriptions

**Goal:** monetize the tenant-safe SaaS using a provider-neutral billing domain with Paystack and Flutterwave adapters.

Payment-provider behavior must never directly determine authorization. Verified billing events update internal subscription state; an internal entitlement service controls product access.

### 11.1 Billing domain

- **P11-DATA-01:** Add `plans`, `plan_prices`, `plan_entitlements`, `billing_customers`, `subscriptions`, `subscription_items`, `payment_transactions`, `invoices`, and append-only `billing_events`.
- **P11-DATA-02:** Store money as integer minor units plus ISO currency. Initial launch supports configured NGN prices; additional currencies require explicit price rows.
- **P11-DATA-03:** Add internal subscription states: Trialing, Active, PastDue, GracePeriod, NonRenewing, Cancelled, Suspended, and Incomplete.
- **P11-DATA-04:** Add immutable provider identifiers, unique idempotency keys, and raw-event hashes; redact sensitive payload fields.

### 11.2 Provider abstraction

- **P11-GW-01:** Define `BillingGateway` methods for customer creation, checkout initialization, verification, subscription management, cancellation, payment-method update, and reconciliation.
- **P11-GW-02:** Implement `PaystackGateway` using hosted transaction initialization and Paystack plans/subscriptions.
- **P11-GW-03:** Implement `FlutterwaveGateway` using hosted checkout and its supported recurring/tokenized flow; provider-specific limitations remain inside the adapter.
- **P11-GW-04:** Use Laravel HTTP client with strict timeouts, retries only for safe/idempotent calls, provider request IDs, and circuit-breaker state.

### 11.3 Secure payment lifecycle

- **P11-PAY-01:** Add trial signup without card, plan selection, monthly/annual prices, hosted checkout redirect, and return page that does not grant access by itself.
- **P11-PAY-02:** Verify Paystack webhook origin with `x-paystack-signature` HMAC-SHA512 over the raw payload.
- **P11-PAY-03:** Verify Flutterwave's `flutterwave-signature` using HMAC-SHA256 and the configured secret hash.
- **P11-PAY-04:** After signature verification, re-query the provider and validate status, amount, currency, reference, customer, and organization before activating service.
- **P11-PAY-05:** Acknowledge webhooks quickly, persist them once, and process them asynchronously and idempotently.
- **P11-PAY-06:** Add a scheduled reconciliation job for pending/missed events and an operator reconciliation screen.
- **P11-PAY-07:** Add failed-payment notices, grace period, retry schedule, expiring-card handling where supported, cancellation at period end, reactivation, and hosted payment-method management.
- **P11-PAY-08:** Do not store card PAN, CVV, or raw payment credentials.

Paystack implementation must follow its official [subscription](https://paystack.com/docs/payments/subscriptions/), [webhook](https://paystack.com/docs/payments/webhooks/), and [verification](https://paystack.com/docs/payments/verify-payments/) contracts. Flutterwave implementation must follow its official [webhook](https://developer.flutterwave.com/docs/webhooks) and current recurring-payment documentation. Re-check these contracts at implementation time because provider APIs change.

### 11.4 Billing UI and operations

- **P11-UI-01:** Add Pricing, Start trial, Billing overview, invoices/receipts, payment status, change plan, cancel, reactivate, and update payment method.
- **P11-UI-02:** Clearly show provider, currency, billing interval, renewal date, grace deadline, and feature consequences.
- **P11-OPS-01:** Add platform configuration for provider availability, test/live mode, plan-code mapping, webhook health, and reconciliation.
- **P11-OPS-02:** Add a dual-provider failover policy for new checkouts only. Never silently move an existing recurring authorization between providers.

### 11.5 Tests and exit gate

- Provider contract tests using recorded, redacted fixtures and `Http::fake()`.
- Signature-valid/invalid, duplicate, reordered, delayed, and replayed webhook tests.
- Amount/currency/reference mismatch tests that never grant entitlements.
- Trial conversion, renewal, failed payment, grace, cancellation, reactivation, and reconciliation tests for both providers.
- Browser tests use provider sandbox/test modes only; CI never uses live credentials.
- Finance reconciliation report must balance internal successful transactions against verified provider transactions.

**Phase 11 demo:** two trial organizations subscribe through different providers, renew through verified webhooks, receive invoices, experience a simulated failed payment/grace period, and recover without cross-tenant effects.

---

## Phase 12 — SaaS onboarding, entitlements, and commercial operations

**Goal:** make the billed SaaS self-service, supportable, measurable, and safe to operate.

### 12.1 Plans and entitlements

- **P12-ENT-01:** Add one internal `EntitlementService` for features and limits. UI hiding is never the only enforcement.
- **P12-ENT-02:** Enforce plan limits for active users, request volume, workflow/request types, storage, integrations, API rate, retention, and evidence exports.
- **P12-ENT-03:** Add usage meters, threshold warnings, upgrade prompts, and soft/hard limit policy.
- **P12-ENT-04:** Make plan changes effective according to documented upgrade/downgrade rules; defer proration until both provider adapters support an identical tested policy.

### 12.2 Self-service onboarding

- **P12-ONB-01:** Add account → organization → trial → workspace onboarding with resumable checkpoints.
- **P12-ONB-02:** Provide starter packs for leave, expense, purchase, document, hiring, IT access, and generic approval.
- **P12-ONB-03:** Add guided setup for departments, members, first request type, first workflow, and first test request.
- **P12-ONB-04:** Add safe demo/sample data that can be removed without touching real records.

### 12.3 Platform operations

- **P12-OPS-01:** Add a separate platform console for organizations, subscriptions, payment events, email/queue health, storage, abuse flags, and feature flags.
- **P12-OPS-02:** Add support access requiring reason, tenant consent or approved emergency policy, expiry, visible banner, and immutable audit.
- **P12-OPS-03:** Add tenant-aware rate limits, storage quotas, background-job fairness, and noisy-neighbor monitoring.
- **P12-OPS-04:** Add tenant backup/export, restore-to-new-tenant, and closure/purge runbooks.
- **P12-OPS-05:** Add privacy policy, terms acceptance/version history, billing emails, tax/business identity fields, and customer support contacts.

**Phase 12 exit:** a new customer can discover pricing, start a trial, create an organization, configure a template, invite users, subscribe, manage billing, and receive support without manual database changes.

---

## Phase 13 — Intelligence and process optimization

**Goal:** add explainable assistance after the product has sufficient stable data and controls. AI is optional per tenant and never bypasses workflow policy.

- **P13-DOC-01:** Add OCR/document extraction through a provider interface with confidence scores and human confirmation.
- **P13-RSK-01:** Add deterministic duplicate detection and policy/anomaly flags before any probabilistic model.
- **P13-AI-01:** Add request and discussion summaries with source links to the underlying fields/comments.
- **P13-AI-02:** Add suggested routing/approvers as recommendations only; the published workflow remains authoritative.
- **P13-AI-03:** Add natural-language analytics that compiles to tenant-scoped, read-only metric queries with visible definitions.
- **P13-AI-04:** Add workflow-draft generation from plain language, followed by normal validation, simulation, human review, and publication.
- **P13-GOV-01:** Add tenant opt-in, provider/model configuration, redaction, retention, cost limits, prompt-injection defenses, evaluation datasets, and decision logs.

**Phase 13 exit:** AI-disabled tenants have no AI data transfer or functional loss; AI outputs are attributable, reviewable, rate-limited, and unable to mutate workflow state directly.

---

## Phase 14 — SaaS stabilization and general availability

**Goal:** freeze major feature development and prove that the SaaS is secure, reliable, supportable, and commercially ready before enterprise/mobile work begins.

- **P14-REL-01:** Run a 60–90 day controlled beta with defined cohorts and migration/support playbooks.
- **P14-REL-02:** Resolve all severity-1/2 defects and documented top usability failures.
- **P14-REL-03:** Complete penetration test, tenant-isolation review, payment review, privacy review, and accessibility audit.
- **P14-REL-04:** Run load, failover, queue backlog, provider outage, webhook replay, backup restore, and disaster recovery exercises.
- **P14-REL-05:** Define SLOs for availability, API latency, queue latency, notification delivery, webhook processing, and recovery objectives.
- **P14-REL-06:** Add public status, incident communication, support escalation, and maintenance procedures.
- **P14-REL-07:** Validate unit economics: provider fees, storage, email/SMS, queue volume, support load, and plan limits.
- **P14-REL-08:** Publish stable API/OpenAPI versioning, deprecation policy, release notes, onboarding guides, and administrator documentation.

**Phase 14 exit:** two consecutive release cycles meet SLOs with no critical isolation, payment, data-loss, or workflow-integrity incident. Only then may Phase 15 begin.

---

## Phase 15 — Enterprise capabilities and native mobile apps

**Goal:** add enterprise identity/governance and native mobile clients only after the SaaS web platform is stable. Enterprise and mobile are parallel workstreams sharing the stable v4 API; neither may weaken tenant isolation or workflow controls.

### 15A. Enterprise capabilities

- **P15-ENT-01:** SAML 2.0 SSO with organization-specific identity providers, signed metadata, certificate rotation, domain verification, and break-glass access.
- **P15-ENT-02:** SCIM 2.0 user/group provisioning with dry-run reconciliation, deprovisioning safeguards, and audit trail.
- **P15-ENT-03:** LDAP/Active Directory synchronization for self-hosted enterprise deployments.
- **P15-ENT-04:** Organization login policies, enforced MFA, session duration, IP allowlists, and managed-device policy hooks.
- **P15-ENT-05:** Multi-entity hierarchy, business units, delegated administration, cross-entity reporting, and data residency policy metadata.
- **P15-ENT-06:** Customer-managed encryption-key abstraction, advanced legal holds, configurable retention, and compliance evidence APIs.
- **P15-ENT-07:** Sandbox environments and controlled workflow promotion from sandbox to production.
- **P15-ENT-08:** Enterprise audit/API exports, SIEM integration, service accounts, scoped OAuth applications, and higher-rate bulk APIs.
- **P15-ENT-09:** Contract/SLA entitlements, purchase-order invoicing, manual enterprise billing, and account-management notes separate from gateway subscriptions.

### 15B. Native mobile applications

- **P15-MOB-01:** Choose the client stack through an ADR after measuring team capability; consume the stable API rather than duplicating Laravel business rules.
- **P15-MOB-02:** Ship iOS and Android authentication with OAuth/passkeys where supported, secure token storage, device/session revocation, and optional biometric re-entry.
- **P15-MOB-03:** Add approval inbox, search/filter, request detail, attachment preview, comments, approve/reject/return/information request, and delegation.
- **P15-MOB-04:** Add push notifications with deep links and stale-step confirmation.
- **P15-MOB-05:** Add requester forms, camera/document upload, drafts, and explicitly scoped offline draft synchronization.
- **P15-MOB-06:** Approval actions remain online-only and are processed by the server `WorkflowEngine`; the client never derives authoritative state.
- **P15-MOB-07:** Add mobile accessibility, localization readiness, crash reporting, privacy manifests, app-store release automation, and device-matrix tests.

### Phase 15 exit

- Enterprise identity/provisioning passes interoperability and deprovisioning tests.
- Native apps pass the same policy/action contract suite as the web/API clients.
- Offline conflict resolution cannot overwrite submitted or newer server data.
- Mobile approval attempts against stale steps fail safely.
- App-store builds, privacy disclosures, support procedures, and phased rollout are complete.

---

## 5. Cross-phase test strategy

| Layer | Required coverage |
|---|---|
| Unit | Rule compiler, graph validator, resolvers, entitlement calculations, payment signatures, provider mapping |
| Domain/feature | Workflow transitions, tenant scoping, billing lifecycle, retention, permissions, idempotency |
| Contract | Billing gateways, connectors, AI providers, mobile/API behavior |
| Browser | Request builder, workflow builder, requester submission, approver decision, billing checkout return, accessibility |
| Migration | Fresh install plus upgrade fixtures from every major release |
| Concurrency | Approval races, publish races, webhook duplicates, subscription changes, quota enforcement |
| Security | Tenant isolation, signed URLs/actions, field redaction, webhook signatures, support access |
| Performance | Large lists, analytics, workflow fan-out, exports, queues, storage, tenant fairness |

Every bug involving authorization, workflow state, money, tenancy, data loss, or audit integrity requires a regression test before the fix.

## 6. Data migration sequence

Major migrations must use expand → backfill → validate → constrain → contract:

1. Add nullable/new structures without breaking old code.
2. Deploy code that dual-reads or dual-writes where necessary.
3. Backfill using resumable, idempotent commands with progress and dry-run modes.
4. Validate counts, hashes, foreign keys, and sample records.
5. Add non-null/unique constraints only after validation.
6. Remove old paths in a later release, never in the same risky deployment.

## 7. Risk register

| Risk | Mitigation |
|---|---|
| Workflow regressions during visual-builder/version migration | Immutable versions, compatibility fixtures, engine coverage, old-instance completion tests |
| Cross-tenant data exposure | Explicit context, fail-closed services, isolation suite for every model/path/job |
| Incorrect paid access from spoofed/replayed payment events | Raw-body signature checks, provider re-verification, idempotent events, internal entitlements |
| Provider outage or API drift | Gateway adapters, reconciliation, circuit breakers, sandbox contract checks, current official docs |
| Shared-hosting regression | Preserve database queue/file cache mode; no runtime Node/Redis requirement in self-hosted mode |
| UI complexity overwhelms non-technical users | Preview, templates, progressive disclosure, validation, simulation, usability tests |
| AI leaks data or makes unauthorized decisions | Tenant opt-in, redaction, evaluations, recommendations only, engine remains authoritative |
| Enterprise/mobile work destabilizes SaaS | Phase 15 blocked until Phase 14 stability gate passes |

## 8. Handoff protocol for engineers and AI agents

Before starting work:

1. Read `AGENTS.md`, this plan, `CHANGELOG.md`, and the relevant document in `docs/`.
2. Run `git status -sb`, identify the current branch, and do not overwrite unrelated changes.
3. Find the first incomplete task in the active phase and confirm its prerequisites are merged.
4. Inspect existing Actions, Services, Policies, tests, and UI patterns before introducing a new abstraction.
5. Write or update an ADR before implementing a decision marked `ADR` in this plan.
6. Add the failing tests first for workflow, tenancy, payment, or security behavior.

When completing work:

1. Update the task's status in a PR description or future checked task tracker; do not mark an entire phase complete prematurely.
2. Record migrations, configuration keys, queue/scheduler changes, feature flags, and rollback steps.
3. Update documentation and `CHANGELOG.md` in the same release.
4. Report exact commands and test counts used for verification.
5. State the next unblocked task ID so another contributor can continue immediately.

The plan is intentionally specific, but implementation details may change through an ADR when evidence shows a safer or simpler approach. The ordering and phase exit gates may only change through an explicit plan update reviewed alongside the affected architecture and migration strategy.
