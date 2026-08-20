# Changelog

All notable changes to ApproveDesk are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- Optional organization office hours on the business calendar. Workflow steps that use Business hours count only inside the saved window on working, non-holiday days; empty hours keep today’s 24-hour working-day behaviour.
- Searchable assignee picker with chips on the workflow builder and template form, and an optional E.164 phone number on user records for later SMS and WhatsApp delivery.
- Phase 7 exit tests: resolver and completion contracts, generated graph validation, concurrent group decisions and publish races, and a v2.1 in-flight upgrade that finishes on the original workflow version.
- Dynamic workflow routing: assignee resolvers (including managers, form-field users, and an external resolver interface), group completion rules, business-calendar deadlines, custom outcomes such as approve with conditions, and request-type selection rules with an administrator explanation.
- Visual workflow builder with canvas and list views, session undo/redo, publish-time graph validation, read-only simulation, clone, version compare, and restore-as-draft.
- Versioned workflow templates: published graphs are frozen, instances bind to a version, and a later publish retires the previous version without rewriting in-flight work.
- Request-type workflow assignment: each form selects the template for new submissions, many named templates may be active, and one default remains the fallback.
- Form-builder field keys are generated from labels and hidden from administrators.
- Feature definitions article in the Help centre, and a phase-exit rule that Help must define and explain every shipped feature.
- Administrator knowledge base in `docs/kb/` rendered as the in-app Help centre, with a compact Help menu and per-screen life-ring links that do not add Administration clutter.
- Local/testing-only design-system reference covering typography, colour, actions, feedback, loading, empty, modal, and responsive-table patterns.
- Authenticated help centre and product-level Help and Notifications navigation.
- Reusable semantic page-header, alert, validation-summary, empty-state, and skeleton Blade components.
- Policy-scoped global request search across reference, title, requester, status, and metadata, with desktop and mobile entry points plus Command/Control-K access.
- Notification centre with live unread badges, mark-read controls, policy-safe request links, pagination, and preserved delivery-frequency labels.
- Reusable breadcrumbs and back-navigation across submissions, approvals, notifications, and administration screens.

### Changed

- Help copy for Business hours now includes office hours. Analytics overdue counts use the step’s deadline calendar instead of treating every step as elapsed.
- Workflow templates no longer exclusively own Document, Note, Memo, or Request. Those labels are optional; routing follows the request type assignment.
- Request type builder condition and calculated-field controls pick earlier fields by label instead of raw keys.
- Standardized product typography, accessible light/dark tokens, focus treatment, component surfaces, reduced-motion behavior, and touch target sizing.
- Converted the submissions table into labelled cards on narrow screens and improved the core dashboard, submission, and approval empty states.
- Removed Laravel starter-kit repository and documentation links from authenticated production navigation.
- Reordered dashboard content into requester, approver, administrator, mixed-role, and viewer modes so the most important available action appears first.

## [2.0.0] - 2026-08-17

### Added

- Laravel 13, Livewire 4, Flux UI, and Tailwind CSS application scaffold.
- Sequential, parallel all-of, and quorum N-of-M approval engine with transactional row locking and append-only audit actions.
- Submission wizard with draft autosave, private attachments, signed downloads, and comments.
- Approver inbox, workflow timeline, queued email/database notifications, and role-aware dashboards.
- First-run web installer with requirements checks, database configuration, initial super-admin creation, and self-disabling lock.
- Login branding settings and registration controls.
- Administration for users, roles, departments, and sequential workflow templates.
- Shared-hosting database queue schedule and staging/production deployment automation.
- Time-limited approval delegation with delegate notifications and scheduled expiry cleanup.
- Conditional submission-data routing and append-only system-audited automatic approval steps.
- Sanitized TipTap submission bodies, signed PDF/image previews, MIME-restricted uploads, and advanced submission filters.
- Information-request pause/response/resume, guarded manual escalation, and immediate/daily/weekly/in-app notification preferences.
- Step-level SLA deadlines with scheduled escalation, automatic approval/rejection outcomes, and overdue indicators.
- Admin approval analytics with period-based performance metrics, daily activity charts, bottleneck detection, operational follow-up, and CSV, Excel, and PDF exports.
- Personal Sanctum API tokens, versioned submission/approval REST endpoints, and signed, retried outbound workflow webhooks with an endpoint-management screen.
- One-click email approvals with an administrator-selectable signed-link lifetime (1–720 hours), confirmation screen, stale-step protection, throttling, and policy authorization.
- Google and Microsoft OpenID Connect login with state validation, PKCE, verified identity linking, and registration-policy enforcement.
- Production container packaging for Docker Compose, EasyPanel, Coolify, and compatible platforms, while retaining cPanel/Plesk deployment support.
- End-to-end Phase 1 acceptance coverage for three-level approval and rejection flows.

### Security

- Policy gates on submission files and all administration actions.
- Inactive-account login and session blocking.
- Final-super-admin protection and restricted super-admin role assignment.
- Immutable workflow action models and documented production database privilege hardening.
