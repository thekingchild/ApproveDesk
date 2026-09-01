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

**Administrator knowledge base through Phase 9.3 (2026-08-24):** feature definitions (including public website, workflow version, builder, graph validation, selection rule, assignee resolver, group completion, business calendar / office hours, custom outcome, assignee picker, follower, mention, reminder, out of office, expected next step, estimated due time, branding, notification channel, evidence pack, auditor access, legal hold, retention, disposition, sensitive field, download stamp, device session, operations, scheduler heartbeat, failed delivery, stuck request, and backup check), getting around (including the public `/` marketing home versus signed-in Dashboard, and Administration → Operations), requests, approvals, collaboration, statuses, account settings (including out of office and that in-app only does not mute optional channels), users and roles (including E.164 phone numbers), departments, workflow templates (including upgrade guarantees and the searchable assignee picker), request types (including workflow assignment and selection rules), analytics, webhooks, organization settings (including login branding, application logo, favicon, meta title and description, office hours, and reminder quiet hours), notification channels, the audit trail, records, security and privacy, and operations.

### 3.5 Request types own workflows

Locked product decision for work after Phase 6. It does not reopen Phases 0–6.

- A request type (the published form) selects the workflow template used for new submissions of that type.
- Any number of named templates may be active at once. Exclusive ownership of Document, Note, Memo, or Request by an active template is not the routing model going forward.
- Exactly one default template remains, as fallback for General Request and for any type with no assignment.
- Document, Note, Memo, and Request stay as General Request fields where they already exist. They are not the routing key.
- In-flight workflow instances keep the template they started with. Changing an assignment or the default affects only new submissions.
- Requesters pick a form. They do not pick a workflow.

Phase 6A implements this assignment. Phase 7 keeps its original scope: versioned templates, visual builder, and later selection rules on form values. Phase 7 must not revert exclusive submission-type locking.

### 3.6 Notification channels — generic vs administrator options

Locked product decision for Phase 8.2. It does not reopen Phase 8.1. Design: [docs/design/8.2-notification-channels.md](docs/design/8.2-notification-channels.md).

- **Generic (always on):** in-app (database) inbox and email. Email uses built-in copy until an administrator publishes an override. No extra credentials. Signed approval links stay on action-required email.
- **Administrator options (off until configured):** Microsoft Teams, Slack, WhatsApp, and Telegram. Each needs credentials, a health/test action, and identity mapping. Unconfigured channels skip; they do not fail the notification or the workflow.
- **WhatsApp** uses WhatsApp Business Platform **template mapping**: fetch approved Meta templates, map each ApproveDesk notification `kind` to a template name + language + placeholders. Do not send free-form WhatsApp for these events.
- **Editable email templates** are an organization override of generic email copy (subject, body, merge fields, restore default). Action-required templates must keep the signed confirmation URL. Stored in ApproveDesk; not Meta/WhatsApp copy.
- **Telegram** is outbound notification through a bot (webhook, not long-polling). Messages may include the same signed review URL as email. Interactive in-chat approve/reject is not required in 8.2 (that remains Teams/Slack in P8-NOT-02).
- Users still choose **when** mail is sent (immediate / digest / in-app only) on their own Notifications settings. They do not enable org-level bots.
- SMS and web push stay in the channel interface as originally planned; they are not part of this administrator-options set until their own tasks (SMS with WhatsApp phone storage; web push with P8-NOT-03).

## 4. Release map and status

| Phase | Target | Theme | Status |
|---|---|---|---|
| 0–4 | v2.0.0 | Existing workflow platform | Complete |
| 5 | v2.1.0 | Non-enterprise UX, accessibility, and approver productivity | Complete |
| 6 | v2.2.0 | Configurable request types and form builder | Complete |
| 6A | v2.2.1 | Request-type workflow assignment | Complete |
| 6B | v2.2.2 | Form builder refinements (dates, preview, draft-time checks) | Complete |
| 7 | v2.3.0 | Visual workflows, versioning, and dynamic assignment | Complete |
| 7A | v2.3.1 | Assignee picker UX and user phone numbers | Complete |
| 7B | v2.3.2 | Organization working / office hours | Complete |
| UO | v2.3.3 | User onboarding: CSV/Excel import, registration OTP, domain allow-list | Complete |
| LP | v2.3.4 | Public static landing page | Complete |
| 8 | v2.4.0 | Collaboration, notifications, and integration hub | Complete |
| 9 | v2.5.0 | Governance, audit evidence, security, and reliability | Complete |
| IN | v2.5.1 | Installer lock and in-app update | Complete |
| MFA | v2.5.2 | Enforce two-factor authentication | Complete |
| TS | v2.5.3 | Cloudflare Turnstile | Complete |
| BK | v2.5.4 | In-app database and file backups | Complete |
| E2FA | v2.5.5 | Optional second-factor assurance for signed approval URLs | Complete |
| SH-OWN/REC/CAP | v2.6.0 | Self-hosted system owner, account recovery, and active-user capacity | Complete |
| SH-RBAC/BTIME/CRON | v2.7.0 | Custom roles, backup time pickers, and scheduler diagnostics | Complete |
| SH-BK271 | v2.7.1 | Backup date-time names, restore history, SQLite promote | Complete |
| SH-SEC272 | v2.7.2 | Security hardening: OAuth domain gate, owner-only custom code, backup digests, outbound URL checks, APP_TIMEZONE | Complete |
| SH-PRN273 | v2.7.3 | Professional printable approval reports | Complete |
| SH-IN274 | v2.7.4 | Shared-host installer and Cloudflare compatibility fixes | Complete |
| SH-LP275 | v2.7.5 | Optional public landing page with login-first default | Complete |
| SH-COM280/SH-THM280 | v2.8.0 | Owner announcements and selectable application themes | Complete |
| SH-UX281 | v2.8.1 | Dashboard announcements, company identity, printable branding, and collapsible navigation | Complete |
| SH-SEC282 | v2.8.2 | Security assessment remediation: role ceilings, web-push SSRF, API scope, bounded imports, safe exports | Complete |
| SH-UX283 | v2.8.3 | Public landing page refresh, capability-scoped Help, and printable status stamp | In progress |
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

## Phase 6 — Configurable request types and form builder — Complete (2026-08-19)

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

## Phase 6B — Form builder refinements — Complete (2026-08-22)

**Goal:** close the gaps found in a review of the Phase 6.2 builder: date fields ignore their min/max settings, the preview is not a real preview, schema problems surface only on save/publish, condition value pickers are blind to the source field's choices, and unsaved builder work can be lost. This phase changes no published-schema contract and no stored configuration shape; it only makes the builder honor what administrators already configured, show problems earlier, and protect work in progress.

This phase is additive. Phases 0–7 stay complete as written; Phase 8 task IDs and scope stay unchanged. Published versions remain immutable. No migration is required: `min`/`max` already exist on every field's configuration and are simply now enforced for date types.

### 6B.1 Validation correctness — Complete (2026-08-22)

- **P6B-FORM-01 — Complete:** `RequestSchemaCompiler::typeRules()` applies the configured `min`/`max` to Date and DateTime fields as `after_or_equal:`/`before_or_equal:` rules, exactly as it already does for Number and Currency. Values already stored under published versions are unaffected.
- **P6B-FORM-02 — Complete:** On publish, a choice field whose configured `default` is not one of its options is rejected with a clear message, as is an inverted date range (earliest after latest).
- **P6B-FORM-03 — Complete:** Date bounds that are not real dates (pure numbers such as `1`/`2`, or free text) are rejected at save/publish with "Date limits must be actual dates in YYYY-MM-DD format". This closes the min=1/max=2 trap where Laravel read the numbers as Unix timestamps and made every date invalid. The builder also stores date bounds as strings — they are no longer cast to float, which previously corrupted even correctly typed dates.

### 6B.2 Builder experience — Complete (2026-08-22)

- **P6B-UI-01 — Complete:** The builder's Preview form renders each field with its real control type (select/radio options, checkbox, date inputs, number ranges) instead of a disabled plain text input, honors visibility conditions against sample values entered in the preview, and shows required-when state.
- **P6B-UI-02 — Complete:** The builder surfaces draft-time warnings inline before save: choice fields without options, conditions referencing a missing earlier field, defaults missing from their option list, date min/max inversions, calculated fields with no sources, and date limits that are not real dates. Saving/publishing keeps the existing server-side validation as the authority; warnings are advisory and never block drafting.
- **P6B-UI-03 — Complete:** Condition value pickers (`Show when`, `Require when`) offer the referenced source field's options as choices when that field is select/radio/multi-select, falling back to free text otherwise.
- **P6B-UI-04 — Complete:** Builder state autosaves per browser as a named local draft (per request type when editing), debounced on field and name changes, with a saved-at indicator, a restore/discard banner on reopen, and automatic discard once a server save or publish succeeds. Server drafts remain the durable record; the local snapshot never reaches the server.
- **P6B-UI-05 — Complete:** The bounds controls are per field type: date and date-time fields get calendar date pickers labelled "Earliest date requesters can choose" / "Latest date requesters can choose" with a plain-English summary ("Requesters can pick dates from 1 Sep 2026 up to 31 Dec 2026"); number and currency fields keep numeric Minimum value / Maximum value boxes; other field types show no bounds controls at all.

### 6B.3 Tests, docs, and exit gate — Complete (2026-08-22)

- **P6B-TEST-01 — Complete:** Unit tests cover date min/max rule generation and enforcement (inclusive bounds), default-not-in-options rejection at publish, inverted-range rejection, numeric/free-text date bounds rejection, and unchanged rules for fields without min/max.
- **P6B-TEST-02 — Complete:** Feature tests cover the live preview honoring visibility/required conditions, the draft-checks panel listing an empty choice field and an inverted date range (and clearing after the fix), option-aware condition value pickers, local-draft restore/discard scoping (matching type only, discard blocks restore), calendar date pickers with plain-language labels and a bounds summary for date fields, numeric bounds for currency fields, and the not-a-real-date warning for the 1/2 trap.
- **P6B-DOC-01 — Complete:** `docs/kb/request-types.md` gained "Dates and defaults" and "Preview and draft checks" sections; the Help catalogue entry already covers the article; `CHANGELOG.md` updated.

**Phase 6B demo:** an administrator sets a leave window with date min/max and sees out-of-range submissions rejected; builds a form where the preview hides a conditional section until the sample value matches; sees an inline warning for a choice field with no options before ever pressing Publish; and refreshes the page mid-edit without losing fields.

**Out of scope (later phases):** richer operators beyond equals/not_equals/in/not_empty for form logic, additional calculation operations, new field types such as phone or heading blocks, whole-schema environment export/import, and undo/redo parity with the Phase 7 workflow builder.

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

This phase is additive. Phases 0–7 stay complete as written. Phase 7A stays complete as written. Phase 6 stays complete as written. Phase 8 task IDs and scope stay unchanged. Do not reopen exclusive Document/Note/Memo/Request locking. Do not rewrite P7-RTE-03.

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

## Branding identity — Complete (2026-08-21)

**Goal:** make a saved login background actually appear on the login page, and let administrators set the application logo, favicon, browser title, and meta description without waiting for multi-tenant organization records.

This is additive. It does not reopen Phases 0–8. No new table. Values stay in the `settings` table and are read through `App\Support\Branding` (cache-backed). Tenant-scoped organization logos remain P10-TEN-01.

- **BRAND-01 — Complete:** Login background, logo, and favicon files are stored on the public disk under `branding/` and served by `GET /branding/assets/{file}`. The login page no longer depends on `php artisan storage:link` or an `APP_URL`-prefixed `/storage/...` URL, which is why a saved background was stored but not visible. The image is shown in the left panel on large screens and behind the sign-in form on narrower viewports.
- **BRAND-02 — Complete:** Branding settings include application name (`brand.meta_title`), meta description (`brand.meta_description`), application logo (`brand.logo`), and favicon (`brand.favicon`), in addition to the existing tagline, accent colour, background, overlay, custom CSS, and registration toggle. The logo replaces the default mark on login and in the signed-in sidebar/header. Favicon and meta tags apply on every page that uses `partials.head` and on the welcome page.
- **BRAND-03 — Complete:** Saving branding goes through `UpdateBrandingSettingsAction` and `BrandingSettingsData`. Replacing or removing a file deletes the previous object. Missing stored files fall back to the product defaults instead of emitting a broken URL.
- **BRAND-TEST-01 — Complete:** Feature tests cover default and custom identity on login, background and identity uploads through the settings page, the asset route (success, missing file, path traversal, disallowed extension), and restoration of defaults when logo/favicon are removed.
- **BRAND-TEST-02 — Complete:** Feature tests also cover the redesigned settings layout: grouped Application identity and Login page sections with context subtitles, inline current-asset thumbnails on upload cards, plain-language constraint copy (types and size limits next to each picker), Advanced custom-CSS disclosure, and a live preview with Login and Browser tabs.
- **BRAND-DOC-01 — Complete:** Help for organization settings and the Branding definition, `CHANGELOG.md`, `docs/DEVELOPMENT.md` (storage link plus route fallback), installer/post-deploy `storage:link` best-effort, and this plan, per section 3.4.

**Branding identity demo:** an administrator uploads a login background, logo, and favicon, sets the application name and meta description, saves, signs out, and sees the background, logo, tab title, and description on `/login` without running `storage:link`.

### Branding settings page redesign — Complete (2026-08-23)

Follow-up UX pass over the same page. No new settings, no schema change; this only reorganizes how the existing controls are presented.

- **BRAND-UX-01 — Complete:** The page is organized into two titled cards — **Application identity** ("Appears on login and throughout the app") and **Login page** ("What requesters see before they sign in") — replacing a single long form.
- **BRAND-UX-02 — Complete:** Logo, favicon, and background uploads render as visual cards with inline thumbnails of the current or freshly chosen asset, plain-language constraint copy (file types and size limits beside each picker), a "Keep current … instead" undo link after choosing a replacement, and an explicit "will be removed when you save / Keep it" state instead of a bare checkbox alone.
- **BRAND-UX-03 — Complete:** Custom CSS moves under an "Advanced" disclosure so it cannot be mistaken for a required field, self-registration sits with identity settings, and the save button stays visible at the end of the form column.
- **BRAND-UX-04 — Complete:** The live preview becomes a sticky side panel with two tabs: **Login** (background, overlay, tagline, logo/name) and **Browser** (favicon + title/description in a mock browser tab, plus the signed-in header mark), so both branding surfaces are verifiable before saving.

**Redesign demo:** an administrator opens Branding, sees current logo/favicon/background thumbnails at a glance, replaces the logo (thumbnail updates instantly, one click to undo), checks both preview tabs, and saves with confidence about what changed.

**Out of scope:** restyling the signed-in application chrome beyond logo and name; email template logos (Phase 8.2 editable email); per-tenant branding (P10-TEN-01); SVG uploads (XSS risk on a public asset route).

---

## User onboarding & self-registration controls — Complete (2026-08-23)

**Goal:** let large organizations onboard hundreds of users without one-at-a-time entry, and turn open registration from an all-or-nothing switch into a controlled, verifiable gate: administrator-chosen activation behaviour, email OTP confirmation, and domain allow-listing. This is the standard enterprise pattern (SSO-less tenancy) and directly serves BASL-style deployments where only `@company.com` addresses may self-register.

This phase is additive. It does not reopen Phases 0–8. Registration stays off unless enabled; every new control defaults to safe. No change to roles/permissions semantics, `WorkflowEngine`, or append-only history.

### UO.1 CSV / Excel user import — Complete (2026-08-23)

- **UO-DATA-01 — Complete:** Import accepts `.csv` and `.xlsx` (PhpSpreadsheet for xlsx, native parsing for CSV with UTF-8 BOM tolerance and delimiter sniffing). A downloadable template with header `name,email,department,manager_email,phone,roles` plus a sample row is on **Administration → Users → Import users**.
- **UO-RULE-01 — Complete:** Row-level validation: unique email (case-insensitive), E.164 phone if present, department matched by existing name (no implicit creation), manager matched by email (must exist or appear earlier in the file), roles limited to those the acting admin may grant. Invalid rows never abort the batch.
- **UO-RULE-02 — Complete:** Dry-run first: preview table with per-row status (will create / exists / problem) before anything is written. Commit is explicit. Result summary reports created/skipped/updated; a downloadable problem-report CSV is offered when any row is not ready.
- **UO-ACT-01 — Complete:** `UserImportService` parses, analyzes, and commits in 200-row chunks inside a transaction. This delivery runs in-request (shared-hosting safe, no extra queue worker). Files above a few hundred rows should be split; a queued job remains future work. Imported users are organization-managed and marked verified immediately — they are not put through the self-registration OTP gate.
- **UO-ACT-02 — Complete:** Duplicate mode chosen at import time: **skip** (default) or **update** name/department/manager/phone/roles of existing accounts. Passwords are never imported or updated. New accounts get a generated password; people set their own via “Forgot password”.
- **UO-UI-01 — Complete:** “Import users” on the Users screen (policy-gated) with template download, upload, dry-run preview, commit, and result. Non-admins cannot open the page.
- **UO-SEC-01 — Complete:** Uploads are Livewire temporary files (not web-served, size-capped at 10 MB). Each commit writes a `users.imported` log line with actor id, counts, and duplicate mode.

### UO.2 Self-registration activation & email OTP — Complete (2026-08-23)

- **UO-REG-01 — Complete:** When **Allow self-registration** is on, administrators choose **Require email confirmation** (default) or **Grant immediate access** on Branding, with plain-language copy.
- **UO-REG-02 — Complete:** Confirmation is a 6-digit OTP emailed via `RegistrationOtpMail`, valid 10 minutes, single-use, max 5 attempts, resend throttled (60s between sends, 5/hour). Codes are stored hashed. Until confirmed, login bounces to the confirmation screen; no session is kept.
- **UO-REG-03 — Complete:** Successful confirmation sets `email_verified_at` and sends the person to login. Unconfirmed accounts older than 72 hours are pruned by `users:prune-unconfirmed` (scheduled daily at 03:45).
- **UO-REG-04 — Complete:** Fortify’s signed-link email-verification routes remain available. The OTP bounce only applies to addresses that currently have a pending OTP, so it does not lock unverified accounts that never self-registered.

### UO.3 Registration domain allow-list — Complete (2026-08-23)

- **UO-DOM-01 — Complete:** Administrators may restrict self-registration to listed domains (one per line). Empty list = any domain. Comparison is case-insensitive; subdomains match only when explicitly listed.
- **UO-DOM-02 — Complete:** Enforcement is in `CreateNewUser` before the account exists. Rejection copy is generic (“Please register with your organization email address.”) and does not enumerate the allow-list.
- **UO-DOM-03 — Complete:** Branding card “Registration domains” textarea with hostname validation. Applies to self-registration only — administrator-created and imported users are unaffected.

### UO.4 Tests, docs, and exit gate — Complete (2026-08-23)

- **UO-TEST-01 — Complete:** Unit tests cover domain matching (case, subdomain, empty list) and OTP hashing, expiry, attempt/consume, and resend throttle.
- **UO-TEST-02 — Complete:** Feature tests cover import template download, CSV dry-run + commit (duplicates, unknown department, manager link), XLSX parse/commit, policy gate, activation modes, blocked sign-in pre-confirmation, wrong-code rejection, 72-hour pruning, settings save/validation, and domain allow-list on the real `/register` route.
- **UO-TEST-03 — Deferred:** Dusk wizard walkthrough is not in this delivery; Livewire feature tests cover the same contract.
- **UO-DOC-01 — Complete:** `docs/kb/users-and-roles.md`, `docs/kb/organization-settings.md`, `docs/kb/definitions.md`, Help catalogue summaries, and `CHANGELOG.md`.

**Phase UO demo:** an administrator downloads the template, uploads a spreadsheet, reviews the dry-run, and commits; a new hire self-registers with an allowed organization domain, enters the 6-digit email code, and signs in — while a `@gmail.com` attempt is refused when the allow-list is set.

**Professional recommendation (recorded for scope discipline):**
- **Shipped in this order:** UO.2 → UO.3 → UO.1, as recommended.
- **Deliberately excluded (later phases):** SSO/SAML/OIDC (Phase 15 territory), SCIM directory sync, per-domain default role/department mapping, invitation-link bulk onboarding, SMS OTP, and a queued importer for multi-thousand-row files.
- **Why OTP over magic-link here:** a short numeric code on the waiting screen is simpler for non-technical staff than hunting a link in a second tab. Fortify’s signed-link flow stays for admin-initiated verification.
- **Security posture:** OTP at rest is hashed; domain checks are an onboarding convenience, **not** an authentication boundary — anyone can type any domain, so real assurance still comes from passwords/2FA/passkeys.

---

## Phase LP — Public static landing page — Complete (2026-08-23)

**Goal:** make `/` a real marketing home that lives entirely in one drop-in directory, so the public website can be designed or replaced outside this repository without touching Laravel, Livewire, Vite, or Flux.

This phase is additive. It does not reopen Phases 0–8. It does not change Fortify `home` (`/dashboard`), the PWA `start_url`, installer behaviour, branding settings, or `WorkflowEngine`. The stock Laravel `welcome` view is retired as the public home.

**Why a separate directory:** Femi will replace this site with static files built elsewhere. Every website file must live under `website/` and nowhere else. Updating the landing page is a folder swap, not a PHP change.

### How to work with the `website/` directory

This subsection is the working contract. Implementation must match it. Replacement sites must follow it.

#### Layout

```
website/
  README.md       # this contract, kept across replacements when possible
  index.html      # required — served at GET /
  css/            # optional
  js/             # optional
  images/         # optional
  …               # any other static files the site needs
```

- `website/` sits at the **repository root**, next to `app/` and `public/`. It is **not** inside `public/` (that folder already holds `index.php`, `sw.js`, Vite `build/`, and favicons).
- `index.html` is the only required page. Additional HTML (`about.html`, `pricing/index.html`) is allowed and is served if the path is free.
- The default site shipped in this phase is committed. Replacements are also committed in place. Do **not** gitignore `website/` — a fresh clone must still have a home page.
- No Blade, PHP, Livewire, Alpine-from-the-app, Tailwind-from-the-app, or Flux in this directory. No `npm run build` for the landing page.

#### Auth hook (required)

A static file cannot see the Laravel session. The directory stays dumb; the app owns one stable seam.

Every `index.html` (and any other HTML that should show Login / Sign up / Dashboard) must include **exactly one** empty element in the header:

```html
<nav data-approvedesk-auth></nav>
```

When Laravel serves that HTML it replaces the element’s inner HTML:

Guest, registration open (`Branding::registrationOpen()`):

```html
<nav data-approvedesk-auth data-state="guest">
  <a href="/login" data-auth="login">Log in</a>
  <a href="/register" data-auth="register">Sign up</a>
</nav>
```

Guest, registration closed:

```html
<nav data-approvedesk-auth data-state="guest">
  <a href="/login" data-auth="login">Log in</a>
</nav>
```

Signed in:

```html
<nav data-approvedesk-auth data-state="user">
  <a href="/dashboard" data-auth="dashboard">Dashboard</a>
</nav>
```

Style the hook from the static site’s own CSS (`[data-approvedesk-auth]`, `[data-auth="login"]`, `[data-auth="dashboard"]`). Do not depend on Flux or `app.css`.

If a replacement `index.html` omits the hook, Laravel inserts a minimal fallback bar immediately after `<body>` so Login / Dashboard still work. Replacement sites should still put the hook in their own header.

Injection is **server-side** on every HTML response from `website/`. Do not use a client fetch that flashes “Log in” then “Dashboard”. HTML is `Cache-Control: private, no-store`.

#### How to replace the site

1. Build the site anywhere (hand-written HTML, Eleventy, export from a designer tool). The build output must be static files only.
2. Put `<nav data-approvedesk-auth></nav>` in the header of `index.html`.
3. Use **relative** asset URLs (`css/app.css`, `images/hero.webp`). From `/` they resolve to `/css/app.css` and `/images/hero.webp`, which Laravel serves from `website/` when the path is free.
4. Do **not** use paths that belong to the app (see reserved paths below).
5. Delete the current files under `website/` (keep `README.md` if you want the contract to remain).
6. Copy the new files into `website/`.
7. No migration, no PHP edit, no `npm run build`. Reload `/`.

That is the whole update path. If a replacement site is a zip of `index.html` + `css/` + `images/`, unzip it into `website/`.

#### What Laravel serves

| Request | Result |
|---|---|
| `GET /` | `website/index.html` with auth injection |
| `GET /css/app.css` (file exists under `website/`, path not an app route) | that file, correct MIME type |
| `GET /about` or `/about.html` | `website/about.html` or `website/about/index.html` if present |
| `GET /dashboard`, `/login`, `/admin`, … | **app wins** — website files never shadow these |
| Uninstalled app | `EnsureAppConfigured` still redirects `/` to `/install` |
| Logout | already redirects to `route('home')` — the visitor sees the landing page with Log in again |

**Reserved paths** (never claim these in the static site): `/login`, `/register`, `/dashboard`, `/admin`, `/settings`, `/submissions`, `/approvals`, `/analytics`, `/help`, `/notifications`, `/api`, `/install`, `/livewire`, `/build`, `/storage`, `/sanctum`, `/up`, `/branding`, `/manifest.webmanifest`, `/integrations`.

**Safety:** resolve files only inside `realpath(base_path('website'))`. Refuse `..`, absolute paths, dotfiles (`.env`, `.git`), PHP, and anything that is not a regular file. Allow a tight extension list: `html`, `htm`, `css`, `js`, `map`, `svg`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `ico`, `woff`, `woff2`, `ttf`, `eot`, `txt`, `xml`, `json`, `webmanifest`, `pdf`, `mp4`, `webm`.

#### What not to do

- Do not put landing-page files in `public/`, `resources/views/`, or `resources/css/`.
- Do not compile the landing page through Vite.
- Do not change Fortify `home` — signed-in product use stays `/dashboard`.
- Do not teach the static site to call internal Livewire or admin APIs.
- Do not put secrets, `.env`, or Blade in `website/`.

### LP.1 Directory and serving — Complete (2026-08-23)

- **LP-DIR-01 — Complete:** Create `website/` with `README.md` restating this contract and a first `index.html` that already contains the auth hook.
- **LP-SRV-01 — Complete:** Replace `Route::view('/', 'welcome')` with a `PublicWebsiteController` (thin) plus `App\Support\PublicWebsite` (path resolve, MIME, HTML injection). `GET /` is named `home`.
- **LP-SRV-02 — Complete:** Register a **last** fallback GET route that serves a safe file from `website/` only when no earlier Laravel route matched. App routes, Fortify, settings, Livewire, and `/build` stay first. Retire `resources/views/welcome.blade.php`.

### LP.2 Auth-aware header — Complete (2026-08-23)

- **LP-AUTH-01 — Complete:** Inject Login + Sign up for guests and Dashboard for authenticated users into `[data-approvedesk-auth]`. Fallback bar after `<body>` if the hook is missing.
- **LP-AUTH-02 — Complete:** Omit Sign up when `Branding::registrationOpen()` is false (same setting as Branding → Allow self-registration). `Route::has('register')` is not sufficient — Fortify still registers the feature.

### LP.3 First site — Complete (2026-08-23)

- **LP-SITE-01 — Complete:** Ship a self-contained marketing home in `website/` (HTML + CSS, optional JS/images). Header uses the hook. Body is ApproveDesk product copy, not the Laravel “Let’s get started” page. No Vite, no Flux. This site is a placeholder that can be deleted and replaced.

### LP.4 Tests, docs, and exit gate — Complete (2026-08-23)

- **LP-TEST-01 — Complete:** Feature tests: guest `/` is 200 and contains Log in + Sign up and the shipped site copy (not “Let’s get started”); authenticated `/` contains Dashboard and not Log in; registration closed omits Sign up; a file under `website/css/` is served with `text/css`; `/dashboard` and `/login` are still the app; path traversal, dotfiles, and missing files 404; `ExampleTest` and logout → `home` still pass; uninstalled app still redirects `/` to the installer.
- **LP-DOC-01 — Complete:** `website/README.md`, `docs/DEVELOPMENT.md`, `CHANGELOG.md`, Help (`docs/kb/getting-around.md`, `docs/kb/definitions.md`, Help catalogue), and this plan.

**Phase LP demo:** a visitor opens `/` and sees the static marketing site with Log in and Sign up; after signing in and returning to `/`, the header shows Dashboard; an administrator deletes `website/*` (except the README), drops in a new `index.html` that contains `<nav data-approvedesk-auth></nav>` plus its own CSS, reloads `/`, and sees the new site with the same auth buttons — no PHP change.

**Out of scope:** CMS, multi-page admin editor, per-tenant marketing sites (P10-TEN-01), SEO console, blog, localizing the marketing site, changing `/dashboard` or the PWA start URL, compiling the site through Vite, Phase 8.4.

**Next after this phase:** Phase 8.4 Tests and exit gate, then close Phase 8 as v2.4.0.

---

## Phase 8 — Collaboration, notifications, and integration hub — Complete (2026-08-23)

**Goal:** keep work moving without requiring every participant to remain inside ApproveDesk and provide useful integrations without custom code.

### 8.1 Collaboration — Complete (2026-08-20)

- **P8-COL-01 — Complete:** Add watchers/followers with explicit view permission, notification preferences, and self-unfollow.
- **P8-COL-02 — Complete:** Add @mentions, mention notifications, thread replies, edit window, and append-only edit history.
- **P8-COL-03 — Complete:** Add manual reminders/nudges with rate limits, audit records, and quiet-hour handling.
- **P8-COL-04 — Complete:** Add out-of-office schedules, future delegation, fallback approvers, and conflict detection.
- **P8-COL-05 — Complete:** Add requester-visible expected next step and estimated due time.

### 8.2 Notification delivery — Complete (2026-08-21)

Follow section 3.6. Design: [docs/design/8.2-notification-channels.md](docs/design/8.2-notification-channels.md).

- **P8-NOT-01 — Complete (2026-08-21):** Channel interface and registry for database, email, web push, Microsoft Teams, Slack, SMS, WhatsApp, and Telegram. Database and email stay generic and always on. Teams, Slack, WhatsApp, and Telegram are administrator options on Settings → Notification channels (enable, encrypted credentials posted outside Livewire, test connection, identity mapping). Unconfigured, unmapped, or failed optional drivers skip and do not fail the notification or the workflow. SMS and web push remain interface members and stay disabled. Bot tokens are not Livewire public properties and are redacted from logs and last-error text. WhatsApp send waits for P8-NOT-04 template mapping; Telegram uses a webhook route (no long-poll).
- **P8-NOT-02 — Complete (2026-08-21):** Teams and Slack action-required messages include a signed Approve control plus an Open-in-ApproveDesk URL. Inbound Slack/Teams HTTP verifies the platform signature or organisation enablement, then the ApproveDesk token. Expiry, current-step, and `approveViaEmail` policy match email approvals. Message delivery never mutates workflow.
- **P8-NOT-03 — Complete (2026-08-21):** Installable PWA shell (manifest, service worker, offline page), browser web push with VAPID keys and per-user subscriptions, phone-friendly attachment picking, local draft cache, and decision controls that disable while offline. Approvals still require a live server.
- **P8-NOT-04 — Complete (2026-08-21):** Editable email templates per kind with preview and restore; action-required copy must keep `{{approval_url}}`. WhatsApp catalog fetch and mapping; send is `type=template` only and skips when unmapped or unapproved. Telegram uses the same rendered copy as email. Delivery receipts, quiet-hour deferral of optional channels, and up to three retries for failed action-required chat.

### 8.3 Integration hub — Complete (2026-08-23)

- **P8-INT-01 — Complete:** Connector contract (`Connector`) plus registry: triggers (workflow completed), actions, encrypted credentials, health checks, per-connector rate interval, and redacted error logs.
- **P8-INT-02 — Complete:** Initial connectors — Microsoft Teams, Slack, Google Drive, SharePoint/OneDrive, Gmail/Outlook, and generic HTTP. Unconfigured or disabled connectors skip and do not fail the workflow. Live providers are faked in CI.
- **P8-INT-03 — Complete:** Post-approval actions on workflow completion: upload file, send email, create/update an external record, or invoke a signed webhook. Actions may target one request type or all types.
- **P8-INT-04 — Complete:** Credentials encrypted at rest, posted outside displayed secrets, test connection, least-privilege copy on the hub, queue retry with backoff, dead-letter status, and replay.
- **P8-INT-05 — Complete:** REST API request-type schemas, cursor pagination on submissions, Idempotency-Key on mutating calls, `request-types:read` scope, and OpenAPI at `/api/v1/openapi.json`.

Administrator screen: **Administration → Integrations**. Help: `docs/kb/integrations.md`.

### 8.4 Tests and exit gate — Complete (2026-08-23)

- **P8-TEST-01 — Complete (2026-08-23):** Connector contract test suite with faked providers; no live service dependency in CI.
- **P8-TEST-02 — Complete (2026-08-23):** Webhook/action idempotency and replay tests.
- **P8-TEST-03 — Complete (2026-08-23):** Signed-action tampering and stale-step tests for Teams/Slack/email.
- **P8-TEST-04 — Complete (2026-08-23):** WhatsApp mapping tests: fetch cache, bind placeholders, skip when unmapped/unapproved; no live Graph calls.
- **P8-TEST-05 — Complete (2026-08-23):** Editable email template tests: override generic copy, restore default, action-required still contains a valid signed confirmation URL.
- **P8-TEST-06 — Complete (2026-08-23):** Telegram outbound tests with a faked Bot API; long-polling is not used.
- **P8-TEST-07 — Complete (2026-08-23):** Offline draft conflict tests and online-only decision enforcement.
- **P8-TEST-08 — Complete (2026-08-23):** Queue failure and retry tests using the database driver.
- **P8-DOC-01 — Complete (2026-08-23):** Help centre, `CHANGELOG.md`, and this plan.

**Phase 8 demo — Complete (2026-08-23):** a request starts from a custom form, notifies an approver in Teams or Slack (and, if configured, WhatsApp or Telegram), receives a policy-checked decision, uploads the approved document to Drive/SharePoint, and records every delivery/action.

---

## Phase 9 — Governance, audit evidence, security, and reliability — Complete (2026-08-24)

**Goal:** make the mature non-enterprise product defensible, recoverable, and operationally trustworthy before introducing multiple paying tenants.

### 9.1 Audit and records — Complete (2026-08-24)

- **P9-AUD-01 — Complete (2026-08-24):** Generate a per-request evidence pack containing request/version data, document hashes, workflow version, assignments, actions, comments, delegations, notifications, and timestamps.
- **P9-AUD-02 — Complete (2026-08-24):** Sign evidence-pack manifests and add a verification command/page that detects altered files or records.
- **P9-AUD-03 — Complete (2026-08-24):** Add auditor access with read-only, time-bounded scopes and export logs.
- **P9-AUD-04 — Complete (2026-08-24):** Add configurable retention schedules, legal holds, disposition review, and storage lifecycle jobs.
- **P9-AUD-05 — Complete (2026-08-24):** Add request and attachment version history with before/after summaries and immutable provenance.

### 9.2 Security and privacy — Complete (2026-08-24)

- **P9-SEC-01 — Complete (2026-08-24):** Perform formal threat models for authentication, files, integrations, workflow actions, exports, and upcoming SaaS tenancy.
- **P9-SEC-02 — Complete (2026-08-24):** Add per-field sensitivity, export redaction, download watermarking option, and audit of sensitive-field access.
- **P9-SEC-03 — Complete (2026-08-24):** Add secret rotation procedures, CSP, security headers, session/device management, and suspicious-login notifications.
- **P9-SEC-04 — Complete (2026-08-24):** Add rate-limit profiles for login, API, approval links, exports, webhooks, and integrations.
- **P9-SEC-05 — Complete (2026-08-24):** Add self-service user data export and administrator-assisted deletion/anonymization that preserves required audit evidence.

### 9.3 Reliability and operations — Complete (2026-08-24)

- **P9-OPS-01 — Complete (2026-08-24):** Add structured logs, correlation IDs, queue/job metrics, webhook/connector health, and scheduler heartbeat.
- **P9-OPS-02 — Complete (2026-08-24):** Add backup/restore verification tooling and a documented restore drill.
- **P9-OPS-03 — Complete (2026-08-24):** Add dead-letter management, safe replay, stuck-workflow diagnostics, and reconciliation commands.
- **P9-OPS-04 — Complete (2026-08-24):** Establish performance budgets and load tests for 100k submissions, 1m actions, large attachments, and high approver fan-out.
- **P9-OPS-05 — Complete (2026-08-24):** Add zero-downtime-compatible migrations and release rollback runbooks.

**Phase 9 exit — Complete (2026-08-24):** security review findings are resolved or explicitly accepted; restore drill succeeds; evidence pack verification succeeds; load targets meet documented budgets; the application is declared ready for tenant isolation work.

---

## Phase IN — Installer lock and in-app update — Complete (2026-08-24)

**Goal:** after first-run setup, `/install` is gone for every path, and later database updates happen from a signed-in administrator screen — never by reopening the installer.

This phase is additive. It does not reopen Phases 0–9, UO, or LP. It does not change `WorkflowEngine`, branding, or the first-run wizard steps. File/code deploys stay CI/rsync/host — ApproveDesk does not download zip updates over itself.

**Locked decisions**

- Once `storage/app/installed.lock` exists or `APP_INSTALLED=true`, **every** `/install` and `/install/*` URL (including `/install/done`) returns 404. The completing session sees a flash on `/login`, not a leftover installer page.
- Updates are not a second installer. **Administration → Updates** (settings.manage) shows the running version, pending migrations, `migrate:preflight` status, and an **Apply database updates** action that runs preflight then `migrate --force`. It reminds the administrator to take a backup first.
- There is no in-app file self-updater. Overwriting PHP from the browser is unsafe on shared hosting and fights the rollback runbook in `docs/OPERATIONS.md`.

### IN.1 Installer disappearance — Complete (2026-08-24)

- **IN-LOCK-01 — Complete (2026-08-24):** `EnsureAppConfigured` 404s `install` and `install/*` with no exception for `install/done` once installed.
- **IN-LOCK-02 — Complete (2026-08-24):** `InstallController::done` is unreachable after finalize. Completion copy is a one-time login flash (`install.completed` session key consumed on `/login`).
- **IN-LOCK-03 — Complete (2026-08-24):** Visiting `/install`, `/install/welcome`, `/install/database`, `/install/migrate`, `/install/admin`, `/install/done` after install is 404 for guests and for signed-in admins.

### IN.2 In-app update (database only) — Complete (2026-08-24)

- **IN-UPD-01 — Complete (2026-08-24):** Add `Administration → Updates` (or an Updates card on Operations) gated by `settings.manage`. Show app version (from `config/app.php` or a `VERSION` file kept in sync with CHANGELOG), pending migration file names, and last apply time.
- **IN-UPD-02 — Complete (2026-08-24):** **Check updates** runs `MigrationPreflight` on pending files. Destructive pending migrations are refused with the preflight flags — same contract as `php artisan migrate:preflight`.
- **IN-UPD-03 — Complete (2026-08-24):** **Apply database updates** requires a typed confirmation, runs preflight then `migrate --force`, writes a structured log line with actor and correlation id, and never calls `migrate:rollback`.
- **IN-UPD-04 — Complete (2026-08-24):** If Phase BK has shipped, the screen links to **Backup now** and refuses apply when no successful database backup exists in the last 24 hours (setting, default on). If BK has not shipped, show a plain reminder that a host backup must exist.

### IN.3 Tests, docs, and exit gate — Complete (2026-08-24)

- **IN-TEST-01 — Complete (2026-08-24):** Extend `InstallerTest`: after the sqlite flow, every installer URL is 404; `/login` is 200 and shows the completion flash once; a second `/login` does not repeat it.
- **IN-TEST-02 — Complete (2026-08-24):** Feature tests: submitter cannot open Updates; admin sees pending migration names from a fake pending file or an empty list; apply with a clean preflight runs migrate; apply with a dropColumn pending file is refused; apply does not mutate `workflow_actions` except through normal migrations.
- **IN-DOC-01 — Complete (2026-08-24):** `docs/DEPLOYMENT.md`, `docs/OPERATIONS.md`, Help (`docs/kb/getting-around.md` or a short Updates note in `docs/kb/operations.md`), `docs/kb/definitions.md`, Help catalogue if a new article is needed, `CHANGELOG.md`, and this plan.
- **IN-BOOT-01 — Complete (2026-08-28):** A fresh shared-host zip opens `/install` with no `.env`, no `APP_KEY`, and no database. `PrepareFirstRunEnvironment` writes the key and file session/cache before `EncryptCookies`; `Setting::get` returns defaults when the cache or settings table does not exist.
- **IN-NAV-01 — Complete (2026-08-28):** Welcome **Continue** is a GET to `/install/database`. It must not POST to the database save route (that submits empty credentials and the page appears stuck).
- **IN-DB-01 — Complete (2026-08-28):** After MySQL is saved, `runMigrations()` syncs runtime config from `.env` so `migrate` does not run against the example SQLite connection.

**Phase IN demo — Complete (2026-08-24):** finish `/install`; `/install` and `/install/done` 404; login shows “ApproveDesk is ready” once; an administrator opens Updates, sees “no pending migrations”, and cannot reopen the wizard.

**Out of scope:** rewriting the first-run wizard, Docker/CI pipelines, zip self-update, Phase 10 tenancy, changing `/up`.

---

## Phase MFA — Enforce two-factor authentication — Complete (2026-08-24)

**Goal:** let an administrator require a second factor before anyone uses the signed-in product, and turn that requirement off again without rewriting Fortify.

This phase is additive. It does not reopen Phase 9.2, Fortify feature flags, passkeys, or email approval links. Existing optional 2FA and passkeys stay.

**Locked decisions**

- Setting `auth.two_factor_required` defaults to **off**.
- When on, a signed-in user may use the product only if they have **confirmed TOTP** or **at least one passkey**. A passkey already is a strong factor; requiring TOTP as well would lock out passkey-only staff.
- Enforcement is a middleware after `auth` + `verified`, redirecting to **Settings → Security** with a banner. It does not replace Fortify’s challenge on login.
- The administrator who turns the requirement on must already satisfy it. They can turn it off to recover a lockout.
- Signed email/Teams/Slack approval links stay token-based. API tokens already issued keep working; **creating** a new personal token requires the actor to satisfy the requirement.
- No grace period in this phase.

### MFA.1 Setting and policy — Complete (2026-08-24)

- **MFA-SET-01 — Complete (2026-08-24):** Persist `auth.two_factor_required` via `SecuritySettings` / `UpdateSecuritySettingsAction`. Checkbox on **Settings → Security & privacy**. Saving on without the actor enrolled is rejected.
- **MFA-SET-02 — Complete (2026-08-24):** `User::hasSatisfiedSecondFactor(): bool` — confirmed 2FA or `passkeys()->exists()`. Put role checks in a policy/middleware, not `hasRole` in a controller.

### MFA.2 Enforcement — Complete (2026-08-24)

- **MFA-ENF-01 — Complete (2026-08-24):** Middleware `EnsureSecondFactorWhenRequired` on the authenticated web group. Exempt: logout, Settings → Security (and Fortify 2FA/passkey endpoints), Help, branded assets, `/up`.
- **MFA-ENF-02 — Complete (2026-08-24):** Dashboard, submissions, approvals, administration, and Updates redirect to Security until enrolled. JSON/Livewire requests return 403 with a clear message.
- **MFA-ENF-03 — Complete (2026-08-24):** Personal access token create path refuses when the requirement is on and the actor is not enrolled.

### MFA.3 Tests, docs, and exit gate — Complete (2026-08-24)

- **MFA-TEST-01 — Complete (2026-08-24):** Off: users without 2FA reach dashboard. On: they are redirected to Security; after confirming 2FA they reach dashboard. Passkey-only user is allowed. Actor cannot enable the setting without being enrolled. Submitter cannot change the org setting.
- **MFA-TEST-02 — Complete (2026-08-24):** Email approval signed link still works without a session 2FA. Existing Sanctum token still authenticates; creating a token while required and unenrolled fails.
- **MFA-DOC-01 — Complete (2026-08-24):** `docs/kb/security-and-privacy.md`, `docs/kb/account-settings.md`, `docs/kb/definitions.md`, Help catalogue summary, `CHANGELOG.md`, and this plan.

**Phase MFA demo — Complete (2026-08-24):** administrator who already has 2FA turns on **Require a second factor**; a colleague who only has a password is sent to Security and cannot open Approvals until they confirm an authenticator or add a passkey; turning the setting off restores access.

**Out of scope:** SMS OTP, hardware-only policy, per-role exceptions, IdP/SAML (Phase 15), forcing 2FA on the installer admin at create time.

---

## Phase TS — Cloudflare Turnstile — Complete (2026-08-24)

**Goal:** give administrators an optional Cloudflare Turnstile check on public auth forms so scripted sign-up and password guessing have to pass a bot challenge, without making Turnstile a hard dependency.

This phase is additive. It does not reopen Fortify, Phase MFA, or installer routes. Unconfigured or disabled Turnstile is a no-op.

**Locked decisions**

- Cloudflare Turnstile only (not reCAPTCHA, hCaptcha, or a custom puzzle).
- Default **off**. Enable, site key, and secret live in organization settings. Secret is encrypted, posted outside Livewire public properties (same pattern as notification channel credentials).
- Surfaces: login, register, forgot-password, reset-password. Not installer, not signed approval links, not API, not Turnstile on every authenticated page.
- When enabled, missing/invalid tokens **fail closed**. When disabled or unconfigured, forms work as today.
- Widget is the managed/invisible mode. Test keys from Cloudflare’s docs are used in CI (`Http::fake` the siteverify endpoint).

### TS.1 Configuration — Complete (2026-08-24)

- **TS-SET-01 — Complete (2026-08-24):** Settings keys `turnstile.enabled`, `turnstile.site_key`, encrypted `turnstile.secret`. **Settings → Security & privacy** (or a Bot protection card). Test action that calls siteverify with a dummy token and shows a redacted result.
- **TS-SET-02 — Complete (2026-08-24):** `Turnstile` support class: enabled?, site key for views, `verify(string $token, ?string $ip): bool`. Timeouts, no secret in logs.

### TS.2 Auth forms — Complete (2026-08-24)

- **TS-UI-01 — Complete (2026-08-24):** Blade partial for the widget + hidden token, included on the four Fortify views. Script from `challenges.cloudflare.com` only when enabled.
- **TS-ENF-01 — Complete (2026-08-24):** Server verification in Fortify authenticate/create-user/reset pipelines (thin action, not business rules in the Livewire/controller). Failed verify: generic “Please try again” — do not reveal bot vs human.

### TS.3 Tests, docs, and exit gate — Complete (2026-08-24)

- **TS-TEST-01 — Complete (2026-08-24):** Disabled: login/register succeed without a token. Enabled + `Http::fake` success: login succeeds. Enabled + missing token or siteverify failure: login/register/reset are refused. Secret never appears in HTML or logs.
- **TS-TEST-02 — Complete (2026-08-24):** Submitter cannot change Turnstile settings. Admin can disable and the next login works without a token.
- **TS-DOC-01 — Complete (2026-08-24):** `docs/kb/security-and-privacy.md`, `docs/kb/definitions.md`, `docs/DEVELOPMENT.md` (test keys), `CHANGELOG.md`, Help catalogue, and this plan.

**Phase TS demo — Complete (2026-08-24):** administrator pastes site key and secret, enables Turnstile, opens `/login` in a private window, and cannot submit until the widget completes; disabling it restores the previous login.

**Out of scope:** Turnstile on comment forms, API, email approval, installer, enterprise WAF, or replacing rate limits.

---

## Phase BK — In-app database and file backups — Complete (2026-08-24)

**Goal:** let administrators schedule, download, retain, and quickly restore **separate** database and file snapshots, to local disk and/or a remote S3-compatible bucket or Google Drive, without treating backups as a second workflow engine.

This phase is additive. It does not reopen Phase 9.3 verification, `workflow_actions` immutability, or the Integration hub. Host backups (JetBackup / Plesk) remain recommended. In-app backups complement them.

**Locked decisions**

- **Two archive kinds, always separate:** a database snapshot and a files snapshot. One job never mixes them into a single blob. Restore can run one kind without the other.
- **Destinations (any combination):** local private disk (`storage/app/private/backups/{database,files}/`, not web-served); S3-compatible (Laravel `s3` driver + custom endpoint — R2, Wasabi, MinIO, Spaces); Google Drive as a **backup destination** with its own encrypted credentials. Do not reuse Integration hub connectors (those fire on workflow completion).
- **Retention (best default):** database 14 daily copies; files 7 daily copies (files are larger). Each kind has its own 3–90 day setting. Never delete the last successful snapshot of a kind. Monthly rollups are out of scope.
- **Schedule:** existing one-minute cron. Default database backup daily at 02:30, files at 02:45 (before retention at 04:15 so a pre-purge copy exists). Each kind can be disabled. Manual **Backup now** per kind.
- **Quick restore:** pick a snapshot → copy to a scratch path → run existing `BackupVerifier` / restore drill → only then **Promote** with typed confirmation. Promote of a database snapshot replaces the live schema in a maintenance window; it never UPDATE/DELETEs `workflow_actions` in place. Files promote writes into a new prefix then swaps. Failed verify refuses promote.
- Credentials encrypted, posted outside Livewire. Secrets redacted from logs and last-error text.
- Shared hosting: no Redis; queue `database`; PHP table dump when `mysqldump` is absent; SQLite is a consistent file copy. Dump timeout and `withoutOverlapping` required.

### BK.1 Domain and storage — Complete (2026-08-24)

- **BK-DATA-01 — Complete (2026-08-24):** Tables `backup_destinations` (kind local|s3|gdrive, encrypted config, enabled) and `backup_snapshots` (kind database|files, destination, path/remote id, bytes, checksum, status, retained_until, verified_at). String status enum. No MySQL ENUM.
- **BK-DATA-02 — Complete (2026-08-24):** Settings: `backup.database_retain_days` (default 14), `backup.files_retain_days` (default 7), `backup.database_hour` / `backup.files_hour`, enable flags.
- **BK-SEC-01 — Complete (2026-08-24):** Local files are on the `local` disk under `backups/`. Download uses a signed temporary URL, same as attachments. Remote upload uses the destination adapter only.

### BK.2 Create and schedule — Complete (2026-08-24)

- **BK-RUN-01 — Complete (2026-08-24):** `CreateDatabaseBackupAction` — SQLite copy or chunked SQL dump of application tables; gzip; sha256; record snapshot; upload to each enabled destination; verify list/get of the remote object.
- **BK-RUN-02 — Complete (2026-08-24):** `CreateFilesBackupAction` — zip of `storage/app/private/submissions` plus branding assets; exclude `backups/`, logs, cache, sessions; same upload/verify path.
- **BK-RUN-03 — Complete (2026-08-24):** Scheduled jobs + **Backup now** on **Administration → Backups** (or Operations). Failures go to dead-letter style status and appear on Operations health. Unconfigured remote destinations skip and do not fail the local copy.
- **BK-RUN-04 — Complete (2026-08-24):** Retention job deletes expired snapshots per kind, keeping at least one successful copy. Legal holds / disposition are not applied to backup files in this phase (live records stay authoritative).

### BK.3 Destinations — Complete (2026-08-24)

- **BK-DST-01 — Complete (2026-08-24):** Local always available when the disk is writable.
- **BK-DST-02 — Complete (2026-08-24):** S3-compatible: key, secret, region, bucket, optional endpoint, path-style flag, prefix. Test connection lists the prefix.
- **BK-DST-03 — Complete (2026-08-24):** Google Drive: service-account JSON or OAuth refresh token stored encrypted; folder id; unattended schedule must work without a browser. Test connection lists the folder. This is not the Phase 8.3 Drive connector.

### BK.4 Quick restore — Complete (2026-08-24)

- **BK-RST-01 — Complete (2026-08-24):** Restore preview: download/copy snapshot to scratch, run `BackupVerifier` (database) or file-count/hash manifest (files). Persist `verified_at`.
- **BK-RST-02 — Complete (2026-08-24):** **Promote database** only after verify. Maintenance flag / 503 for other users. Replace DB from the verified scratch copy. Confirm `workflow_actions` count matches the snapshot manifest. Then `ops:reconcile` (report only).
- **BK-RST-03 — Complete (2026-08-24):** **Promote files** only after verify. Write to `restore-holding/` then swap directories. Do not delete the previous tree until the swap succeeds.
- **BK-RST-04 — Complete (2026-08-24):** Promote is `settings.manage`, typed confirmation of the snapshot id, structured log with actor. No WorkflowEngine calls.

### BK.5 Tests, docs, and exit gate — Complete (2026-08-24)

- **BK-TEST-01 — Complete (2026-08-24):** Database backup of a sqlite test DB produces a gzip whose required tables pass `BackupVerifier`. Files backup includes a fake submission file and excludes `backups/`.
- **BK-TEST-02 — Complete (2026-08-24):** S3 and Drive adapters are faked (`Http::fake` / fake disk). Unconfigured remote skips. Failed remote does not delete the local snapshot.
- **BK-TEST-03 — Complete (2026-08-24):** Retention keeps the last success. Promote without verify is refused. Promote of a tampered dump is refused. Restore drill does not change live `workflow_actions` until promote. Submitter cannot open Backups.
- **BK-TEST-04 — Complete (2026-08-24):** Schedule registers both backup jobs; `withoutOverlapping` present.
- **BK-DOC-01 — Complete (2026-08-24):** Help `docs/kb/backups.md` (new), definitions (backup, database backup, files backup, destination, retain, promote), Operations article updated so “Check this copy” is not confused with a snapshot, `docs/OPERATIONS.md`, `docs/DEPLOYMENT.md` post-install checklist, Help catalogue, `CHANGELOG.md`, and this plan.

### BK.6 Local purge and sizes — Complete (2026-08-24)

- **BK-UI-02 — Complete (2026-08-24):** Snapshot table shows Size. Totals for Database and Files count each archive once.
- **BK-RUN-05 — Complete (2026-08-24):** Remove local copy when a successful remote replica exists. Verify, Download, and Promote fetch from the remote if the local file is gone. Refused when the local file is the only copy.

**Phase BK demo — Complete (2026-08-24):** administrator enables local + an S3-compatible destination, runs **Backup database** and **Backup files**, sees two snapshots, verifies the database snapshot, promotes it onto a disposable copy that still opens `/login`; a 15-day-old files snapshot is pruned while yesterday’s remains.

**Out of scope:** continuous WAL shipping, point-in-time restore, tenant-scoped backups (P12-OPS-04), encrypting the zip with a customer-managed KMS, backing up `website/` marketing files, using Integration hub webhooks as the transport, rewriting Phase 9.3 commands.

---

## Phase E2FA — Optional second-factor assurance for signed approval URLs — Complete (2026-08-26)

**Goal:** let administrators change signed approval URLs from bearer-link authority into expiring entry points that require the named approver to sign in and prove a second factor in the current session before approving.

This is a self-hosted maintenance phase based on immutable tag `v2.5.4` and released as `v2.5.5`. It does not reopen workflow-engine invariants or Phase 10. The setting is off by default for upgrade compatibility. No schema change is required; the existing settings table stores the option.

**Locked decisions**

- The signed URL, expiry, throttling, confirmation screen, current-step check, policy authorization, CSRF protection, transaction lock, duplicate protection, and append-only action remain required.
- When enabled, the authenticated account must exactly match the approver encoded in the signed URL and the same session must contain assurance from a successful authenticator/recovery-code or passkey ceremony. Enrollment alone is insufficient.
- Enabling or disabling takes effect immediately for already-issued links that remain valid.
- `{{approval_url}}` remains required in action-required notification templates. Email, Telegram, WhatsApp, and other messages that open the shared web URL inherit the gate. Direct Teams/Slack action tokens are a separate channel and remain out of scope.

### E2FA.1 Decision and setting

- **E2FA-ADR-01:** Record the bearer-link threat, default-off compatibility, session-assurance definition, identity binding, channel boundary, and no-schema decision. — Complete (2026-08-26)
- **E2FA-SET-01:** Add the administrator-only `approvals.email_require_second_factor` setting beside link lifetime. Only an administrator with a factor and current-session assurance may enable it. — Complete (2026-08-26)

### E2FA.2 Session assurance and route enforcement

- **E2FA-AUTH-01:** Record a user-bound marker in the current session after successful Fortify authenticator/recovery-code or passkey verification; a new or malformed session is not assured. — Complete (2026-08-26)
- **E2FA-AUTH-02:** Require the signed-in account to match the signed route approver, remain active, and remain authorized for the current step. — Complete (2026-08-26)
- **E2FA-ROUTE-01:** Gate confirmation GET and approval POST after signature validation while preserving expiry, throttle, stale-step, policy, CSRF, and `WorkflowEngine` protections. — Complete (2026-08-26)
- **E2FA-AUD-01:** Record generic enforcement outcomes without logging signed URLs, query strings, codes, credential identifiers, session identifiers, or request contents. — Complete (2026-08-26)

### E2FA.3 User experience, tests, docs, and exit gate

- **E2FA-UX-01:** Provide clear login, wrong-account, factor-enrollment, reauthentication, expired-link, and stale-link states without exposing submission details to the wrong account. — Complete (2026-08-26)
- **E2FA-TEST-01:** Prove setting-off compatibility and setting-on denial/success paths for authenticator/recovery-code and passkey users. — Complete (2026-08-26)
- **E2FA-TEST-02:** Prove notification templates, link expiry, current-step authorization, CSRF, and append-only workflow behavior remain intact. — Complete (2026-08-26)
- **E2FA-DOC-01:** Update administrator Help, authentication/security documentation, threat model, changelog, roadmap, and this plan. — Complete (2026-08-26)

**Phase E2FA demo:** an administrator enables the option; a forwarded approval message cannot reveal or approve its request from a guest or different account; the named approver signs in with a second factor and completes the same signed confirmation flow; disabling the option restores the v2.5.4 behavior for valid links.

**Out of scope:** removing `{{approval_url}}`, SMS OTP, SAML assurance levels, per-workflow policy, tenant policy, a configurable assurance-age window, or changing direct Teams/Slack action tokens.

---

## Self-hosted supplement — System owner, account recovery, and active-user capacity — Complete (2026-08-26)

**Goal:** give the installer-created first account protected system-owner authority, let that owner delegate existing non-owner roles and safely recover other users, and enforce a configurable total active-user limit without changing workflow or audit history.

The detailed execution contract is [SELF_HOSTED_SUPPLEMENTARY_IMPLEMENTATION_PLAN.md](SELF_HOSTED_SUPPLEMENTARY_IMPLEMENTATION_PLAN.md). This supplement targets the single-company self-hosted product and does not reopen or implement Phase 10–15. **ApproveDesk SaaS maintains its own roadmap in its own repository or a `saas`-named branch.**

### SH-OWN — System-owner identity, protection, delegation, and bounded visibility — Complete (2026-08-26)

- **SH-OWN-01 — Complete (2026-08-26):** Persist one installer-created system owner and provide an explicit server-admin repair command.
- **SH-OWN-02 — Complete (2026-08-26):** Reserve universal authority and the internal super-admin role for that owner; prevent delegated-admin privilege escalation.
- **SH-OWN-03 — Complete (2026-08-26):** Hide the owner from ordinary management and new-assignment directories while preserving historical attribution and existing in-flight assignments.

### SH-REC — Owner-only account recovery — Complete (2026-08-26)

- **SH-REC-01 — Complete (2026-08-26):** Remove existing-user password changes from general user editing.
- **SH-REC-02 — Complete (2026-08-26):** Add recently reauthenticated owner-only password reset and second-factor/passkey reset with session/token revocation, target notification, and immutable security records.

### SH-CAP — Active-user capacity — Complete (2026-08-26)

- **SH-CAP-01 — Complete (2026-08-26):** Add an unlimited-by-default, singleton active-user limit and exact usage meter.
- **SH-CAP-02 — Complete (2026-08-26):** Enforce the limit transactionally across manual creation/reactivation, registration, first-time OAuth creation, and import.
- **SH-CAP-03 — Complete (2026-08-26):** Add the owner-only capacity control and boundary/concurrency tests.

### SH-DOC — Help, operations, and release isolation — Complete (2026-08-26)

- **SH-DOC-01 — Complete (2026-08-26):** Update in-app Help, operator recovery documentation, changelog, release metadata, and quality evidence without changing the SaaS roadmap.

**Out of scope:** SaaS tenancy, organizations, platform administration, billing, entitlements, custom role design, automatic deactivation, storage/request-volume limits, and rewriting historical workflow actions.

---

## Self-hosted supplement — Custom roles, backup time pickers, and scheduler diagnostics — Complete (2026-08-26)

**Goal:** let the System owner and explicitly delegated role managers create safe feature-oriented custom roles, make daily database/file times pickable, and prove scheduler health and scheduled backup success without changing workflow history or owner protections.

This supplement is self-hosted v2.7.0 work. It does not implement or change ApproveDesk SaaS Phase 10–15.

### SH-RBAC — Custom roles and permission ceiling — Complete (2026-08-26)

- **SH-RBAC-01 — Complete (2026-08-26):** Add a code-owned permission catalogue, protected built-in role profiles, and append-only role-change events.
- **SH-RBAC-02 — Complete (2026-08-26):** Add owner/custom-role-manager role UI with cloning, editing, archive/restore, effective permission summaries, and strict permission ceiling.
- **SH-RBAC-03 — Complete (2026-08-26):** Integrate custom role assignment into user management/import while preserving v2.6.0 built-in effective access and owner-only Administrator grants.

### SH-BTIME — Pickable backup times — Complete (2026-08-26)

- **SH-BTIME-01 — Complete (2026-08-26):** Render database/files schedule values as validated time pickers with application timezone and next-run display.
- **SH-BTIME-02 — Complete (2026-08-26):** Record successful scheduler-command backup evidence separately from manual Backup now.

### SH-CRON — Scheduler health — Complete (2026-08-26)

- **SH-CRON-01 — Complete (2026-08-26):** Add read-only `ops:scheduler-check`, exact heartbeat display, and scheduled-backup health without creating a false heartbeat.
- **SH-CRON-02 — Complete (2026-08-26):** Verify shared-hosting cron guidance, local `schedule:work`, backup scheduling, and full scheduler tests.

### SH-DOC27 — Help and release — Complete (2026-08-26)

- **SH-DOC27-01 — Complete (2026-08-26):** Update Help, deployment/operations docs, changelog, version metadata, and quality evidence; keep SaaS roadmap separate.

---

## Self-hosted supplement — Backup names, restore history, and SQLite promote — Complete (2026-08-26)

**Goal:** name in-app snapshots by the time they were taken, list newest first, record who restored which copy, and let SQLite promote load existing dumps.

This supplement is self-hosted v2.7.1 work. It does not implement or change ApproveDesk SaaS Phase 10–15.

### SH-BK271 — Backup snapshot names and restore trail — Complete (2026-08-26)

- **SH-BK271-01 — Complete (2026-08-26):** Name snapshots with date and time, show a Date and time column, sort newest first, and record restore history on promote.
- **SH-BK271-02 — Complete (2026-08-26):** Load SQLite dumps that contain MySQL `SET FOREIGN_KEY_CHECKS` so existing snapshots can be promoted.

---

## Self-hosted supplement — Security hardening and application timezone — Complete (2026-08-27)

**Goal:** close the v2.7.1 static security findings that are in product scope, and stop hardcoding the application timezone so operators set `APP_TIMEZONE` in `.env`. Target release is **v2.7.2**. Version metadata is bumped in the CI release push.

This supplement is self-hosted v2.7.2 work on `feature/sh-sec272-security-hardening`. It does not implement or change ApproveDesk SaaS Phase 10–15. It does not reopen WorkflowEngine, append-only `workflow_actions`, installer lock design, custom-role ceilings, or backup date-time naming.

**Source:** repository security review of revision `47c823596713fad0c634e9cece1c95ed65fa995e` (v2.7.1). Finding numbers below match that report.

**Explicitly out of scope (Finding 1):** Unauthenticated first-run installer permits deployment takeover. Leave `/install` as the documented public first-run wizard until the lock exists. Do not add an installer secret, CLI enablement gate, or source-network restriction in v2.7.2.

**Also residual (do not “fix” here):** Livewire 4 / Alpine / Flux still need CSP `'unsafe-inline'` / `'unsafe-eval'`. Do not attempt nonce/hash CSP in this release. Structured analytics-provider settings (measurement IDs instead of paste-JS) are not required if custom code is owner-only. HMAC-signing backup manifests is not required if stored SHA-256 + byte size are enforced. Per-user or per-organization timezones remain Phase 10 `P10-TEN-01`.

**Working contract**

- Help stays end-user facing: in-app `/help/{slug}` only, no `.env`, framework, source-folder, or internal-key language.
- Operator facts (timezone env key, private S3 opt-in, digest checks) live in `docs/DEPLOYMENT.md` / `docs/DEVELOPMENT.md`.
- Each sub-phase is independently demoable and must ship its tests, Pint, and Help/changelog notes in the same change.
- Implement in this order: timezone, OAuth, branding custom code, backup integrity, outbound URLs, then docs/exit.
- Default remains UTC when `APP_TIMEZONE` is absent, so existing installs do not change clock behavior until an operator sets the variable.

### SH-TZ — Application timezone from the environment — Complete (2026-08-26)

**Objective:** `config('app.timezone')` reads `APP_TIMEZONE` instead of a hardcoded `'UTC'`.

- **SH-TZ-01 — Complete (2026-08-26):** In `config/app.php` set `'timezone' => env('APP_TIMEZONE', 'UTC')`. Add `APP_TIMEZONE=UTC` to `.env.example` next to the other `APP_*` keys. Do not change `APP_VERSION`.
- **SH-TZ-02 — Complete (2026-08-26):** If the installer writes `.env` and `APP_TIMEZONE` is missing, write `APP_TIMEZONE=UTC`. Do not add a timezone picker to `/install`.
- **SH-TZ-03 — Complete (2026-08-26):** Operator docs (`docs/DEPLOYMENT.md`, `docs/DEVELOPMENT.md`) state that office hours, backup clocks, snapshot names, and scheduler display use `APP_TIMEZONE` (IANA name, default `UTC`). Note DST: use `setTime`, never `addHours(24)`, matching ADR 0001.
- **SH-TZ-04 — Complete (2026-08-26):** Help copy that currently says “application timezone (UTC)” must say the installation timezone and show the live name already used in the UI (`config('app.timezone')`). Do not mention `.env` in Help.
- **SH-TZ-05 — Complete (2026-08-26):** Tests: missing env stays UTC; `APP_TIMEZONE=Africa/Lagos` makes `config('app.timezone')` `Africa/Lagos`; backup next-run / office-hours labels use that value. Existing UTC tests must keep passing with the default.

**Demo:** set `APP_TIMEZONE=Africa/Lagos`, reload Approval settings and Backups; labels and next-run times show `Africa/Lagos`. Remove the variable and the app is UTC again.

**Out of scope:** in-app timezone editor, per-user timezone, tenant timezone, installer UI.

### SH-SEC-OAUTH — OAuth registration respects the domain allow-list (Finding 2) — Complete (2026-08-26)

**Objective:** Google/Microsoft new-user creation uses the same `RegistrationSettings::domainAllowed()` gate as password registration. Linking an existing account is unchanged.

- **SH-SEC-OAUTH-01 — Complete (2026-08-26):** In `app/Actions/Auth/ResolveOAuthUserAction.php`, before `User::create` when registration is open, call `RegistrationSettings::domainAllowed($data->email)`. On denial throw `ValidationException` with the same user-facing message as `CreateNewUser` (`Please register with your organization email address.`). Do not enumerate allowed domains. Do not apply the list to existing-user linking or returning OAuth identities.
- **SH-SEC-OAUTH-02 — Complete (2026-08-26):** Keep the global registration switch, active-user cap, and `email_verified_at` / `is_active` behavior for allowed new OAuth users.
- **SH-SEC-OAUTH-03 — Complete (2026-08-26):** Tests in `tests/Feature/Auth/OAuthAuthenticationTest.php`:
  - allow-list `example.com`; Google new user `someone@gmail.com` is rejected; no user row.
  - allow-list `example.com`; Microsoft new user `person@example.com` is created as submitter.
  - empty allow-list still creates a new OAuth user (current behavior).
  - existing user `person@gmail.com` can still link Google when the allow-list is `example.com`.
  - registration closed still rejects unknown emails.

**Demo:** registration open, allow-list `bluechipanalytical.com`; Google account `attacker@gmail.com` cannot join; `femi@bluechipanalytical.com` can; an existing staff Gmail can still link.

**Out of scope:** treating the allow-list as an authentication boundary; changing OTP/password registration.

### SH-SEC-BRAND — Owner-only custom code (Finding 3) — Complete (2026-08-26)

**Objective:** `branding.manage` can no longer persist organization-wide JavaScript. Custom code remains possible only for the System owner after recent strong authentication.

- **SH-SEC-BRAND-01 — Complete (2026-08-26):** Add catalogue permission `branding.custom_code.manage`: label “Manage custom code”, group Configuration, risk elevated, **`delegable` => false**, depends on `branding.manage`. Do not grant it to built-in Administrator or any custom role. System owner continues to pass via `Gate::before`.
- **SH-SEC-BRAND-02 — Complete (2026-08-26):** `UpdateBrandingSettingsAction` writes `brand.custom_code` only when the actor is authorized for `branding.custom_code.manage`. A `branding.manage` save must not create, change, or clear custom code (ignore the request field).
- **SH-SEC-BRAND-03 — Complete (2026-08-26):** Branding page (`resources/views/pages/settings/branding.blade.php`): hide the Advanced analytics/custom-code editor unless the current user can `branding.custom_code.manage`. Saving a code change requires password confirmation and the same second-factor session assurance used for owner recovery (`password.confirm` + `SecondFactorAssurance`). Visual branding (logo, colors, CSS, registration) stays on `branding.manage`.
- **SH-SEC-BRAND-04 — Complete (2026-08-26):** Existing stored snippets keep rendering through `Branding::customCodeHtml()` and the shared head. Do not strip `'unsafe-inline'` from CSP in this phase.
- **SH-SEC-BRAND-05 — Complete (2026-08-26):** Tests in `tests/Feature/BrandingTest.php` (and catalogue/role tests as needed):
  - a custom role with only `branding.manage` can save tagline/logo and cannot persist or clear `brand.custom_code`.
  - System owner with password confirmation + second-factor assurance can save custom code; without assurance, save is rejected.
  - owner-saved snippet still appears on login and in-app.
  - custom roles UI cannot assign `branding.custom_code.manage`.

**Demo:** a branding manager changes the logo; pasted `<script>` is ignored. The System owner reauthenticates and saves a tracking snippet; it appears on login.

**Out of scope:** removing custom code entirely; nonce CSP; Google Analytics / Plausible structured fields.

### SH-SEC-BKINT — Backup digest and files-hash verification (Findings 4 and 5) — Complete (2026-08-27)

**Objective:** every materialize / verify / download / promote path rejects bytes that do not match the snapshot’s recorded SHA-256 and size, and files ZIPs must match the per-entry manifest exactly.

- **SH-SEC-BKINT-01 — Complete (2026-08-27):** Shared helper (e.g. `App\Services\Backups\BackupIntegrity`): given a local file and `BackupSnapshot`, require `is_file`, `filesize === snapshot.bytes`, and `hash_equals(snapshot.checksum, hash_file('sha256', ...))`. Empty checksum or size mismatch fails closed. Use this before verify, download, and promote, including local copies.
- **SH-SEC-BKINT-02 — Complete (2026-08-27):** `MaterializeBackupSnapshotAction`: fetch remote objects into a unique private temp file under `storage/app/private/backups/.materialize-*`, run the integrity helper, then move onto the recorded path only on success. Never accept a non-empty fetch as sufficient. Clean up temp files on failure.
- **SH-SEC-BKINT-03 — Complete (2026-08-27):** Keep `BackupVerifier` table-string checks as a second pass only after digest success. Do not treat CREATE TABLE presence as integrity.
- **SH-SEC-BKINT-04 — Complete (2026-08-27):** `VerifyBackupSnapshotAction::verifyZip`: after archive-level digest, require an exact entry set (`manifest.files` paths plus the stored `manifest.json`), reject duplicates, extra names, directories, symlinks, absolute/`..`/`\\`/`\0` names; cap entry count and uncompressed size; stream each expected file through SHA-256 and `hash_equals` the manifest hash.
- **SH-SEC-BKINT-05 — Complete (2026-08-27):** `PromoteFilesBackupAction`: extract only verified entries into a fresh private directory, then atomically replace `submissions`. Do not `extractTo` the whole archive. This also closes the deferred ZIP path-traversal follow-up for supported PHP/libzip by never trusting entry names blindly.
- **SH-SEC-BKINT-06 — Complete (2026-08-27):** Tests (`tests/Feature/Backups/`):
  - remote (or swapped) database dump with valid CREATE TABLE text but wrong SHA-256 fails verify and cannot promote.
  - matching digest + tables still verifies and promotes.
  - files ZIP that keeps pathnames but changes bytes fails.
  - files ZIP with extra or `../` entries fails.
  - exact manifest + hashes still verifies and promotes.

**Demo:** replace a remote object with a well-formed dump that would have passed today’s regex; Verify and Promote refuse it. An untouched snapshot still restores.

**Out of scope:** new backup format, HMAC-signed manifests, changing destination credentials, installer.

### SH-SEC-SSRF — Outbound destination validation (Findings 6–9) — Complete (2026-08-27)

**Objective:** one outbound-destination policy used at save and at request time: HTTPS, no userinfo, no unusual ports unless explicitly allowed, resolve A/AAAA, reject private/reserved/loopback/link-local/IPv6 unique-local, pin the connection to the validated address while keeping TLS SNI on the original hostname. Do not expose raw response bodies as a diagnostic oracle.

- **SH-SEC-SSRF-01 — Complete (2026-08-27):** Add `App\Support\OutboundDestination` (rule + resolver + pin). Unit-test literal private IPs, `localhost`, `.local`, public hostname whose lookup is private, DNS rebinding (public at save, private at send), and a public HTTPS host that is allowed. Skip real network in tests by injecting a resolver stub.
- **SH-SEC-SSRF-02 — Complete (2026-08-27):** Webhooks (Finding 8): `ValidWebhookUrl` uses the helper (not only literal IPs). `DeliverWebhook` revalidates and pins before POST. Keep HTTPS-only, no redirects, timeout.
- **SH-SEC-SSRF-03 — Complete (2026-08-27):** Integration connectors (Finding 6): validate `url` / `webhook_url` (and other HTTP URL credential fields) in `IntegrationCredentialController` at save. `ConnectorHttpClient` revalidates/pins before GET/POST. Store a generic `last_error` (status class only); do not persist or render response bodies.
- **SH-SEC-SSRF-04 — Complete (2026-08-27):** Web Push (Finding 7): store and send only HTTPS endpoints that pass the helper. Reject userinfo and non-443 ports. Revalidate at send in `WebPushService`; drop the subscription on failure as today. Do not hard-code vendor push origins (they vary).
- **SH-SEC-SSRF-05 — Complete (2026-08-27):** Custom S3 endpoints (Finding 9): if `endpoint` is set, require HTTPS URL and public resolution unless operator opt-in `BACKUP_ALLOW_PRIVATE_S3_ENDPOINT=true` (documented in `docs/DEPLOYMENT.md` for private MinIO). Revalidate on test and on every transport operation. Bound/redact SDK exception text shown in the UI.
- **SH-SEC-SSRF-06 — Complete (2026-08-27):** Tests for each sink: private hostname rejected at save; rebinding hostname rejected at send; legitimate public HTTPS still delivers; connector failure message has no response body; S3 private endpoint rejected unless the opt-in env is true.

**Demo:** a webhook manager cannot save `https://127.0.0.1/`; a hostname that resolves to `10.0.0.1` is refused at delivery; Slack-style public HTTPS still works.

**Out of scope:** blocking all custom S3 endpoints; changing webhook signing; adding a UI host allow-list (env opt-in is enough for MinIO).

### SH-SEC-DOC — Help, changelog, and release isolation — Complete (2026-08-27)

- **SH-SEC-DOC-01 — Complete (2026-08-27):** Update Help in the same delivery as each sub-phase:
  - `docs/kb/organization-settings.md` — domain list applies to Google/Microsoft sign-up; custom code is System-owner only; times use the installation timezone.
  - `docs/kb/users-and-roles.md` — custom code is not a delegable branding permission.
  - `docs/kb/backups.md` — copies are checked against the fingerprint taken at backup time before restore; extra or altered files are refused.
  - `docs/kb/webhooks.md` and `docs/kb/integrations.md` — destinations must be public HTTPS.
  - Refresh `docs/kb/definitions.md` and `HelpCatalogue` summaries only if terms change. End-user language only.
- **SH-SEC-DOC-02 — Complete (2026-08-27):** `CHANGELOG.md` `[Unreleased]` entries for each shipped control. Do not retitle as `[2.7.2]` or set `APP_VERSION` until the CI release push after this supplement is complete.
- **SH-SEC-DOC-03 — Complete (2026-08-27):** Mark every task and this supplement Complete (date) in this plan and set the release-map row to Complete only when tests (`php artisan test --parallel`) and `./vendor/bin/pint --dirty` are green. Keep SaaS Phase 10–15 untouched. Product version is **v2.7.2** on this release push.

**Phase SH-SEC272 demo:** outside-domain OAuth cannot join; branding managers cannot plant JavaScript; a replaced remote backup cannot verify; private/rebinding destinations cannot be called; backup and office-hour clocks follow `APP_TIMEZONE`. Installer first-run remains as in v2.7.1.

**Out of scope:** Finding 1 installer takeover; SaaS tenancy; CSP nonce migration; changing WorkflowEngine.

---

## Self-hosted supplement — Professional printable approval reports — Complete (2026-08-28)

**Goal:** replace the submission screen's generic browser print with a branded, authorization-safe approval report that reads cleanly on screen, on A4 paper, and as a downloaded PDF. Target release is **v2.7.3**. Version metadata is bumped only on the verified release commit.

This supplement is self-hosted v2.7.3 work on `feature/sh-prn273-approval-report`. It does not implement or change ApproveDesk SaaS Phase 10–15. It does not change workflow decisions, rewrite append-only `workflow_actions`, alter published request schemas, or treat recorded decisions as cryptographic signatures.

**Working contract**

- One report route serves an authenticated HTML preview; a separate throttled route downloads the same report as PDF.
- Existing submission `view` authorization and request-field visibility remain authoritative. A report never reveals a field that the viewer cannot see on the submission.
- Report times use the installation timezone and report decisions exactly as recorded in workflow history.
- The output is document-first: no application navigation, dark mode, action controls, comments editor, follower controls, or attachment-download links.
- No schema change is required.

### SH-PRN-DOC — Report content and presentation — Complete (2026-08-28)

- **SH-PRN-DOC-01 — Complete (2026-08-28):** Add a dedicated branded HTML approval-report preview with organization identity, report title, request reference, prominent status, request summary, body, visible custom fields, attachment manifest, workflow progress, and recorded action history.
- **SH-PRN-DOC-02 — Complete (2026-08-28):** Add A4 print rules with controlled margins, print-safe colours, wrapping for long values, repeating table headings, section page-break protection, and a footer containing the reference, generation time, installation timezone, and page numbering where the renderer supports it.
- **SH-PRN-DOC-03 — Complete (2026-08-28):** Replace the existing `window.print()` control with **Approval report** actions for preview and PDF download. The preview supplies its own Print control and never prints signed-in application chrome.

### SH-PRN-AUTH — Authorization and faithful audit output — Complete (2026-08-28)

- **SH-PRN-AUTH-01 — Complete (2026-08-28):** Authorize preview and PDF through the existing submission `view` policy and return the same denial semantics as the submission page.
- **SH-PRN-AUTH-02 — Complete (2026-08-28):** Reuse the request-field access rules for sensitive and conditional fields; preserve typed display values without exposing internal field keys or database identifiers.
- **SH-PRN-AUTH-03 — Complete (2026-08-28):** Render workflow steps and append-only actions without inventing signatures. Show actor, recorded outcome, installation-timezone timestamp, and comment where present; label system actions as System.

### SH-PRN-PDF — PDF generation and quality gates — Complete (2026-08-28)

- **SH-PRN-PDF-01 — Complete (2026-08-28):** Generate a downloadable PDF from the report view with the existing Dompdf dependency, a sanitized reference-based filename, embedded/local branding where available, and no remote-resource requirement.
- **SH-PRN-PDF-02 — Complete (2026-08-28):** Add feature tests for owner/participant access, stranger denial, sensitive-field redaction, report content, immutable action-history output, preview response, PDF response headers/content, and requests without a workflow or custom form.
- **SH-PRN-PDF-03 — Complete (2026-08-28):** Update the in-app Requests/Approvals Help, definitions if needed, `HelpCatalogue` summaries if needed, and `CHANGELOG.md`; run the full parallel suite, Pint, PHPStan/CI parity, and print/PDF smoke checks before marking this supplement Complete.

**Phase SH-PRN273 demo:** open an approved request, choose **Approval report**, inspect a clean branded preview, print it on A4, and download the same report as a PDF. A person who cannot open the request is denied, and a person without sensitive-field access cannot reveal those values through either report route.

**Out of scope:** cryptographic or handwritten signatures, configurable report templates, merging attachment contents into the PDF, public report links, batch reports, changing workflow state, or adding a new reporting permission.

---

## Self-hosted supplement — Shared-host installer and Cloudflare compatibility — Complete (2026-08-29)

**Goal:** make a clean cPanel/Plesk bundle install reliably without shell access, honor the selected production database, and keep the Livewire/Flux application interactive when Cloudflare proxies the hostname. Target release is **v2.7.4**. No schema change is required.

- **SH-IN274-BOOT-01 — Complete (2026-08-28):** A bundle with no `.env`, application key, or database opens `/install` instead of returning HTTP 500. First-run boot creates a unique key and file-backed session/cache before cookie middleware runs.
- **SH-IN274-NAV-01 — Complete (2026-08-28):** Welcome **Continue** uses GET to open database settings rather than posting empty credentials to the save route.
- **SH-IN274-DB-01 — Complete (2026-08-28):** Saving MySQL/MariaDB updates both `.env` and the running installer configuration before migrations and seeders execute; the example SQLite connection cannot receive a MySQL installation. Custom SQLite paths are applied the same way on a host; PHPUnit’s in-memory database is left alone. Installer `.env` writes quote and escape credentials so `#`, quotes, and `$` survive.
- **SH-IN274-CF-01 — Complete (2026-08-29):** Vite, Livewire, and Flux scripts opt out of Cloudflare Rocket Loader rewriting with `data-cfasync="false"` before `src`, including tags Livewire injects after middleware.
- **SH-IN274-DOC-01 — Complete (2026-08-28):** The operator deployment guide documents Cloudflare Full (strict) SSL, Rocket Loader off, dynamic-route cache bypasses, cache purge, and DNS-only diagnosis. This is server administration, so no end-user Help article changes.
- **SH-IN274-QA-01 — Complete (2026-08-29):** Installer regressions, full SQLite suite, MySQL CI, Pint, PHPStan, assets, and required Quality jobs (`php`, `mysql`, `assets`) passed on PR #9 before tagging.

**Out of scope:** changing workflow state, end-user screens, schema, tenant/SaaS work, or requiring Cloudflare.

---

## Self-hosted supplement — Optional public landing page and login-first default — Complete (2026-08-29)

**Goal:** let an administrator choose whether the public marketing website is available, while making the branded login page the default entry point for a new or upgraded v2.7.5 installation. Target release is **v2.7.5**. No schema change is required; the existing settings table stores the option.

This supplement is self-hosted v2.7.5 work on `feature/sh-lp275-landing-toggle`, based on the GitHub v2.7.4 release. It does not implement or change ApproveDesk SaaS Phase 10–15, the installer, self-registration policy, Fortify's signed-in home, the PWA start URL, `WorkflowEngine`, or append-only workflow history.

**Working contract**

- Setting `brand.landing_page_enabled` defaults to **off**. A guest opening `/` is redirected to the branded login page; a signed-in person opening `/` is redirected to Dashboard.
- An administrator with `branding.manage` may turn **Show public landing page** on or off under **Settings → Branding**. The change takes effect on the next request.
- When on, the existing drop-in `website/` contract, server-side Login/Sign up/Dashboard injection, static assets, and safe-path rules remain unchanged.
- When off, the public website fallback does not serve HTML pages or assets from `website/`; application routes, branding assets, `/up`, and the installer continue to work normally.
- Logout may continue to target the named `home` route: with the landing page off, that route leads guests to login instead of creating a redirect loop.

### SH-LP275 — Setting, routing, and administration — Complete (2026-08-29)

- **SH-LP275-SET-01 — Complete (2026-08-29):** Add the cache-backed landing-page setting to `Branding`, `BrandingSettingsData`, and `UpdateBrandingSettingsAction`, with a safe default of off and no migration.
- **SH-LP275-ROUTE-01 — Complete (2026-08-29):** Make `/` redirect guests to login and authenticated users to Dashboard while the option is off; preserve the current static home when it is on.
- **SH-LP275-ROUTE-02 — Complete (2026-08-29):** Refuse the `website/` fallback while the option is off so disabling the landing page also disables its additional pages and assets. Earlier Laravel routes must still win.
- **SH-LP275-UI-01 — Complete (2026-08-29):** Add a plain-language **Show public landing page** checkbox to **Settings → Branding**, explaining that off makes Login the site address and on serves the drop-in public website.

### SH-LP275-QA — Tests, Help, and release — Complete (2026-08-29)

- **SH-LP275-TEST-01 — Complete (2026-08-29):** Feature tests prove the off-by-default guest and authenticated redirects, enabled public home, disabled fallback assets/pages, enabled static assets, registration-aware auth links, setting persistence, and permission gate.
- **SH-LP275-DOC-01 — Complete (2026-08-29):** Update Getting around, Organization settings, feature definitions, Help catalogue summaries, `CHANGELOG.md`, README/version metadata, and this plan in end-user language.
- **SH-LP275-REL-01 — Complete (2026-08-29):** Run the affected tests, full parallel suite, Pint, PHPStan, asset build, and live `/`, `/login`, and `/up` checks; then publish the verified commit as GitHub release tag **v2.7.5**.

**Phase SH-LP275 demo:** with the option off, a visitor opening the site address lands on Login and a signed-in person lands on Dashboard; an administrator turns on **Show public landing page**, and the same site address immediately serves the drop-in marketing page with the correct Login, Sign up, or Dashboard action.

**Out of scope:** editing marketing content from ApproveDesk, replacing the `website/` folder contract, changing the signed-in Dashboard, redirecting named application routes, changing registration rules, or adding a database migration.

---

## Phase SH-COM280 / SH-THM280 — Owner announcements and application themes — Complete (2026-08-30)

**Goal:** give the System owner a safe organization-wide communication surface and an owner-curated theme catalogue, while letting each person choose an available application palette and their existing Light/Dark/System appearance mode.

This is an additive self-hosted minor release. “Superadmin” in the request maps to the singleton **System owner**, not merely a user holding the legacy `super-admin` role. It does not change workflow state, notification-channel delivery, branding assets, custom CSS ownership, role permissions, or the Phase 10 SaaS boundary.

### SH-COM280 — Announcements

- **SH-COM280-DATA-01 — Complete (2026-08-30):** Add `announcements` plus per-user `announcement_receipts` through additive, SQLite/MySQL-portable migrations. Store creator, sanitized rich-text message, audience (all users or selected role IDs), display mode, publication state/timestamps, and archival metadata. A unique announcement/user receipt records first display and optional acknowledgement without copying one row per target at publish time.
- **SH-COM280-RULE-01 — Complete (2026-08-30):** Support three explicit display modes: **Sticky** remains visible until the System owner archives it and cannot be dismissed; **Acknowledge** remains visible to that user until they choose **Mark as read**; **Show once** records the first rendered display and is hidden on later page loads. Audience matching is server-side, includes any active user with at least one selected role, and treats an empty role selection only as the explicit all-users audience.
- **SH-COM280-SEC-01 — Complete (2026-08-30):** Only the System owner may open announcement administration or create, edit, publish, archive, and republish announcements. Legacy role name alone never grants access. Rich text is sanitized before persistence and authorization is enforced by policy/domain actions, not only hidden navigation.
- **SH-COM280-UI-01 — Complete (2026-08-30):** Add **Administration → Announcements** with clear draft/published/archived states, audience and mode summaries, preview, validation, publish/archive controls, empty/success/error states, and confirmation before ending a sticky message. Published content may be corrected, with the changed content shown on the next eligible render.
- **SH-COM280-UI-02 — Complete (2026-08-30):** Render eligible announcements in a global, accessible banner stack above signed-in page content. Show audience-neutral copy, publication time, and the mode-appropriate action. Keep announcements separate from workflow notifications and email/optional channels.

### SH-THM280 — Built-in and custom application themes

- **SH-THM280-DATA-01 — Complete (2026-08-30):** Add a theme catalogue and nullable per-user theme preference using additive migrations. Seed immutable built-in palettes with stable keys, keep the default palette enabled, and store owner-created custom palettes as validated color tokens. Disabling or archiving a selected theme falls back safely to the organization default without breaking the user record.
- **SH-THM280-RULE-01 — Complete (2026-08-30):** The System owner can enable/disable built-in choices, create/edit/archive custom themes, preview them, and choose one enabled organization default. Built-in token values and stable keys cannot be edited or deleted; at least one theme and the selected default must remain enabled.
- **SH-THM280-SEC-01 — Complete (2026-08-30):** Theme catalogue management is System-owner-only and policy/domain-action enforced. Custom colors accept strict six-digit hex values, are escaped into a fixed CSS-variable template, and never accept arbitrary CSS or markup.
- **SH-THM280-UI-01 — Complete (2026-08-30):** Add **Administration → Application themes** with built-in/custom labels, palette swatches, enabled/default status, custom-theme form, previews, and guarded enable/default/archive actions.
- **SH-THM280-UI-02 — Complete (2026-08-30):** Extend **Settings → Appearance** so every user can save one enabled application theme and independently keep the existing Light/Dark/System mode. Apply the effective palette on every signed-in page, across navigation, focus/accent controls, links, and the browser theme color, with accessible light/dark foreground tokens and a safe default for guests or removed preferences.

### SH-COM280 / SH-THM280 — Tests, Help, and release

- **SH-COM280-TEST-01 — Complete (2026-08-30):** Feature/unit tests cover System-owner-only management, role audience matching, all three display modes, receipt idempotency, sanitization, publish/archive/republish behavior, inactive users, invalid input, and global rendering without leaking an announcement to a non-target role.
- **SH-THM280-TEST-01 — Complete (2026-08-30):** Feature/unit tests cover built-in invariants, custom color validation, owner-only management, enabled/default guards, preference save/fallback, CSS escaping/template output, and appearance-page/global-layout rendering in light/dark/system-compatible markup.
- **SH-COM280-DOC-01 — Complete (2026-08-30):** Add end-user Help articles and definitions for Announcement, Sticky announcement, Application theme, and Organization default theme; register them in `HelpCatalogue`; update Help summaries, `CHANGELOG.md`, README, and this plan.
- **SH-COM280-REL-01 — Complete (2026-08-30):** Back up the local database; prove fresh-install and v2.7.5 upgrade migrations; run targeted tests, the full parallel suite, Pint, PHPStan, asset build, and SQLite/MySQL CI; independently review the diff; merge through a green GitHub PR; publish annotated **v2.8.0**; sync both checkouts; migrate/clear caches locally; and verify `/up`, `/login`, owner administration, an eligible banner, and a saved user theme.

**Phase demo:** the System owner publishes a sticky safety notice to all users and a one-time finance message to selected roles; eligible people see the correct global banner behavior. The owner enables a built-in palette, creates a custom accessible palette, sets the organization default, and a user chooses another enabled palette while retaining System appearance mode.

**Out of scope:** email/chat delivery, scheduled campaigns, file attachments, per-user targeting, comments on announcements, arbitrary theme CSS, user-created themes, login/marketing-page theming, per-tenant theme catalogues, or changes to workflow notifications and branding custom CSS.

---

## Phase SH-UX281 — Dashboard announcements, company identity, printable branding, and collapsible navigation — Complete (2026-08-30)

**Goal:** refine the v2.8 communication and application chrome so notices are useful without following people through every workflow screen, the saved company identity is presented consistently in the app and printable approval records, and desktop navigation can be collapsed and restored without losing orientation.

This is a supplementary self-hosted patch release. It does not change announcement audiences, receipt semantics, workflow state, role permissions, theme tokens, public landing-page behavior, or the Phase 10 SaaS boundary. No schema change is required: the existing sanitized announcement HTML and `brand.meta_title` setting remain the storage contracts.

### SH-UX281.1 — Dashboard announcements and formatting

- **SH-UX281-COM-01 — Complete (2026-08-30):** Render eligible announcement banners only on the named Dashboard route. A Show once receipt is created only when the message is actually rendered on Dashboard; visits to other signed-in pages do not consume it.
- **SH-UX281-COM-02 — Complete (2026-08-30):** Keep each announcement compact by default with a short visual excerpt. Long content has an accessible **Show more / Show less** control that expands in place, preserves sanitized formatting, and does not interfere with **Mark as read**.
- **SH-UX281-COM-03 — Complete (2026-08-30):** Replace the raw message textarea experience with a basic, keyboard-accessible formatting toolbar for bold, italic, underline, bulleted list, numbered list, and safe hyperlinks. Keep `RichTextSanitizer` as the server-side authority and retain a plain HTML-backed field for Livewire compatibility.

### SH-UX281.2 — Company identity and reports

- **SH-UX281-BRAND-01 — Complete (2026-08-30):** Present the existing application-name setting as **Company name** and explain that it is the organization identity used throughout ApproveDesk, browser titles, and printable approval records. Blank continues to fall back to the installed application name for upgrade compatibility.
- **SH-UX281-BRAND-02 — Complete (2026-08-30):** Make signed-in branding resilient to long company names: constrain the sidebar label, wrap or truncate it without widening or displacing menu items, and expose the full value to assistive technology and pointer users.
- **SH-UX281-PRN-01 — Complete (2026-08-30):** Always print the company name in the approval-report masthead, whether or not a logo exists. Lay out logo and name as a professional identity block with bounded logo dimensions and wrapping/overflow rules for long names in browser print and Dompdf output.

### SH-UX281.3 — Collapsible desktop navigation

- **SH-UX281-NAV-01 — Complete (2026-08-30):** Make the desktop sidebar collapsible through a visible labelled control, preserve mobile drawer behavior, and show an equally visible restore control while collapsed. The main content must reclaim the available width without a reload.
- **SH-UX281-NAV-02 — Complete (2026-08-30):** Preserve the desktop collapsed preference across Livewire navigation and browser reloads, retain keyboard focus and accessible names, and keep logo, search, Help, notification, administration, and user-menu access understandable in compact mode.

### SH-UX281.4 — Tests, Help, and release

- **SH-UX281-TEST-01 — Complete (2026-08-30):** Feature-test Dashboard-only rendering, Show once receipt timing, acknowledgement behavior, long-message disclosure markup, formatting controls, safe formatted persistence, company-name labels, long-name-safe chrome, and report masthead output with and without a logo.
- **SH-UX281-TEST-02 — Complete (2026-08-30):** Verify sidebar collapse/restore markup and persistence hooks at desktop and mobile breakpoints; run the full parallel suite, Pint, PHPStan, asset build, SQLite/MySQL CI, and an independent diff review.
- **SH-UX281-DOC-01 — Complete (2026-08-30):** Update Announcements, Organization settings, Getting around, definitions/Help summaries where needed, `CHANGELOG.md`, README, and this plan in the same delivery.
- **SH-UX281-REL-01 — Complete (2026-08-30):** Back up the local database; prove no pending schema work; merge through a green GitHub PR; update every version surface to **2.8.1**; publish and verify annotated tag **v2.8.1** and GitHub release; sync both checkouts; clear local caches; and verify `/up`, `/login`, Dashboard announcements, company branding, printable report, and sidebar collapse/restore.

**Phase demo:** the System owner formats and publishes a long announcement; an eligible person sees a compact excerpt only on Dashboard, expands and collapses it, and can acknowledge it when required. An administrator saves a long company name; the navigation remains usable and the full company identity appears beside the logo in a PDF-ready approval report. On desktop, the person collapses the sidebar, continues working with more horizontal space, and restores it from the visible control.

**Out of scope:** announcement scheduling or delivery outside Dashboard, a full document editor, image/file embeds, arbitrary HTML/CSS, a second company-name setting, per-report branding templates, changing report evidence content, or redesigning mobile navigation.

---

## Phase SH-SEC282 — Security assessment remediation — Complete (2026-08-31)

**Goal:** close the five validated findings from the v2.7.2 repository security assessment on the self-hosted line: permission ceilings on role assignment (including self-service), web-push request pinning and redirect denial, `submissions:read` on the submission detail API, bounded spreadsheet import, and formula-safe analytics exports.

This is a supplementary self-hosted patch release. It does not add schema, does not start Phase 10 SaaS multi-tenancy, and does not change workflow-engine semantics. No migration is required.

### SH-SEC282.1 — Plan and failing tests

- **SH-SEC282-PLAN-01 — Complete (2026-08-31):** release-map row + this phase section
- **SH-SEC282-RED-01 — Complete (2026-08-31):** failing regression tests for all five findings

### SH-SEC282.2 — Remediation

- **SH-SEC282-ROLE-01 — Complete (2026-08-31):** permission ceilings + block self-role changes (GrantableRoleService, RoleAssignmentService, UserPolicy::assignRoles, UpdateUserAction, users Livewire)
- **SH-SEC282-PUSH-01 — Complete (2026-08-31):** pin web-push destinations, disable redirects, harden public-IP CIDRs, WebPushFactory
- **SH-SEC282-API-01 — Complete (2026-08-31):** submissions:read Form Request on submission detail
- **SH-SEC282-IMPORT-01 — Complete (2026-08-31):** ZIP/XML preflight + bounded A:F read filter; no toArray()
- **SH-SEC282-EXPORT-01 — Complete (2026-08-31):** CSV formula prefix neutralization + XLSX explicit string typing

### SH-SEC282.3 — Help and release

- **SH-SEC282-DOC-01 — Complete (2026-08-31):** Help/OpenAPI/DEPLOYMENT/changelog
- **SH-SEC282-REL-01 — Complete (2026-08-31):** full gates, PR, annotated v2.8.2 tag, two-checkout sync

**Phase demo:** a delegated user editor cannot grant Department head or change their own roles; web-push delivery pins DNS, refuses redirects, and drops documentation-range destinations; a write-only API token cannot read submission detail; a hostile XLSX is rejected before spreadsheet load; analytics CSV/XLSX exports treat attacker-controlled `=` prefixes as text.

**Out of scope:** Phase 10 tenancy, installer takeover, CSP nonces, SaaS branches, and version bumps before the CI release push.

---

## Phase SH-UX283 — Public landing page, role-safe Help, and report status stamp — In progress

**Goal:** ship the completed static marketing page through the existing `website/` drop-in, make Help visibility match each person’s real application authority, and make printed approval records immediately recognizable by status without obscuring evidence. Target release is **v2.8.3**.

This is a supplementary self-hosted patch release on `feature/sh-ux283-landing-help-report-stamp`, based on GitHub v2.8.2. It does not add schema, change workflow state, alter the protected System-owner model, start Phase 10 SaaS work, or change the optional landing-page toggle. “Superadmin” in the request maps to the singleton **System owner**, not merely possession of the legacy `super-admin` role.

**Security recommendation and working contract**

- Help uses least privilege: `all` articles remain available to every signed-in person; administration articles require the same capability as their linked screen; owner articles require `User::isSystemOwner()`.
- A broad role label or one unrelated administration permission must not unlock the full Administration Help group. Custom roles see only guides for capabilities they actually hold.
- Mixed guides must not disclose owner-only controls. Owner procedures move into a separate **System owner controls** guide; shared guides may state that a control is owner-only but must not describe its procedure, recovery flow, or sensitive configuration.
- Direct `/help/{slug}` access, Help index cards, menu entries, sibling links, Markdown links, and **Open this screen** actions must all enforce the same audience decision and fail closed.
- The approval-report stamp uses the persisted submission status, is text (not a signature), appears in a bounded upper-right area, and remains non-obstructive in browser preview, browser print, and Dompdf output.

### SH-UX283.1 — Plan and landing-page drop-in — Complete (2026-09-01)

- **SH-UX283-PLAN-01 — Complete (2026-09-01):** Add the v2.8.3 release-map row, security recommendation, scope, tasks, demo, and exit gate before production changes.
- **SH-UX283-WEB-01 — Complete (2026-09-01):** Replace the shipped `website/` marketing files with the completed static landing page, retaining exactly one empty `<nav data-approvedesk-auth></nav>`, relative assets, the 55px wordmark, favicon set, SEO metadata, and external footer links.
- **SH-UX283-WEB-02 — Complete (2026-09-01):** Prove guest Login/Sign up injection, signed-in Dashboard injection, registration-closed behavior, CSS/JS/image/favicon serving, no standalone waitlist submission, and no dependency on Vite, Blade, Livewire, or runtime Node.

### SH-UX283.2 — Capability- and owner-scoped Help — Complete (2026-09-01)

- **SH-UX283-HELP-01 — Complete (2026-09-01):** Replace the broad administrator audience check with explicit article access rules: signed-in, one-or-more required permissions, or singleton System owner. Keep role names out of authorization decisions.
- **SH-UX283-HELP-02 — Complete (2026-09-01):** Assign every administration guide to its screen capability, add a System-owner-only guide for protected owner controls, and remove procedural owner-only content from guides available to delegated administrators or ordinary users.
- **SH-UX283-HELP-03 — Complete (2026-09-01):** Enforce the same rules on the index, direct article route, compact Help menu, related-guide links, Markdown link rewriting, contextual links, and screen actions; unauthorized direct requests return 403 without rendering article content.

### SH-UX283.3 — Printable approval-status stamp — Complete (2026-09-01)

- **SH-UX283-PRN-01 — Complete (2026-09-01):** Add an accessible, stamp-like status marker to approval reports using the persisted submission status label and status-appropriate restrained styling. Keep the existing company identity and textual status semantics.
- **SH-UX283-PRN-02 — Complete (2026-09-01):** Position and size the marker so it does not cover headings or evidence, avoid invented signatures or decision claims, and verify the marker survives HTML preview, A4 browser print, and Dompdf PDF text extraction.

### SH-UX283.4 — Tests, Help, and release

- **SH-UX283-TEST-01 — Complete (2026-09-01):** Add failing-first feature coverage for website replacement/auth injection, permission-specific Help visibility, System-owner-only content, direct-route denial, link unwrapping, and report status stamps across representative statuses.
- **SH-UX283-DOC-01 — Complete (2026-09-01):** Update affected end-user Help, Help catalogue summaries, website contract notes, `CHANGELOG.md`, README, and this plan without exposing deployment internals in Help.
- **SH-UX283-REL-01:** Run focused tests, full parallel SQLite tests, Pint, PHPStan/lint, asset build, PDF smoke checks, independent review, and required GitHub Quality jobs; merge through a green PR, update every version surface to 2.8.3, publish annotated tag and GitHub release **v2.8.3**, synchronize both checkouts, clear local caches, and verify the local server.

**Phase demo:** an administrator enables the public landing page and visitors see the completed static site with server-injected account actions; a custom role sees only Help guides matching its permissions while only the singleton System owner can read protected owner procedures; an approval report displays a clear status stamp without covering its title, company identity, fields, workflow steps, actions, or footer.

**Out of scope:** editing marketing content inside ApproveDesk, changing the landing-page enable/default behavior, disclosing operator deployment material in Help, replacing screen policies with Help checks, adding signatures or seals that imply legal certification, changing submission statuses, modifying append-only workflow history, adding schema, or starting ApproveDesk SaaS.

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
