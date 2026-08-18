# Changelog

All notable changes to ApproveDesk are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- Local/testing-only design-system reference covering typography, colour, actions, feedback, loading, empty, modal, and responsive-table patterns.
- Authenticated help centre and product-level Help and Notifications navigation.
- Reusable semantic page-header, alert, validation-summary, empty-state, and skeleton Blade components.
- Policy-scoped global request search across reference, title, requester, status, and metadata, with desktop and mobile entry points plus Command/Control-K access.
- Notification centre with live unread badges, mark-read controls, policy-safe request links, pagination, and preserved delivery-frequency labels.
- Reusable breadcrumbs and back-navigation across submissions, approvals, notifications, and administration screens.

### Changed

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
