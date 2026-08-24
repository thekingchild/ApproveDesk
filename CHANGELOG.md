# Changelog

All notable changes to ApproveDesk are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- Optional Cloudflare Turnstile (Phase TS): administrators can require a bot check on sign-in, registration, and password reset. Off until a site key and secret are saved and the setting is on. Missing or failed checks are refused with a generic message. The secret is posted outside the settings form and never shown again.
- Enforce two-factor authentication (Phase MFA): administrators can require a second factor (authenticator or passkey) before anyone uses the signed-in product. The person who turns it on must already be enrolled and can turn it off to recover a lockout. Email approval links and existing API tokens stay usable; creating a new token requires enrollment.
- Installer lock and in-app updates (Phase IN): after first-run setup every `/install` address 404s, including the old completion page; login shows **ApproveDesk is ready** once. **Administration → Updates** shows this copy’s version, pending database changes, a safety check, and **Apply database updates** (type APPLY). File deploys stay on the host; this screen does not download a new copy or roll a change back.
- Phase 9.3 reliability and operations: correlation IDs on every response and queued job, a JSON log channel, scheduler heartbeat plus queue/webhook/connector health on **Administration → Operations**, backup verification and a restore drill that never writes over the live database, unified dead-letter replay (webhooks, integrations, notices, failed jobs), stuck-request diagnostics, a read-only reconciliation check, documented performance budgets with query/index tests, and a `migrate:preflight` plus rollback runbook for zero-downtime-compatible releases. Phase 9 is complete.
- Phase 9.2 security and privacy: threat models for authentication, files, integrations, workflow actions, exports, and upcoming tenancy; export redaction and access logging for sensitive fields; optional download stamps; CSP and security headers; device session revoke; suspicious-login notices; configurable rate-limit profiles; self-service data export; administrator account anonymization that keeps request history.
- Phase 9.1 audit and records: per-request signed evidence packs with verification, time-bounded auditor access and export logs, legal holds, retention windows, disposition review, and append-only request/file history.
- Phase 8 exit tests: connector contracts with faked providers, webhook/action idempotency and replay, signed-action tamper/stale coverage for Teams/Slack/email, WhatsApp mapping and placeholder binding, email template restore with a still-valid signed confirmation URL, Telegram outbound on a faked Bot API (no long-poll), offline draft conflict plus online-only decisions, and database-queue retry/dead-letter. A new request form can notify Slack, accept a signed decision, and upload the approved file to Drive in one recorded flow.
- Public static landing page (Phase LP): `/` serves drop-in files from the `website/` directory; the header injects **Log in** / **Sign up** for guests and **Dashboard** when already signed in. Replace `website/` to update the marketing site without changing PHP.
- User onboarding (Phase UO): CSV/XLSX user import with template, dry-run preview, skip/update duplicate modes, and a problem-report download; self-registration activation mode (email OTP confirmation by default, or immediate access); hashed 6-digit confirmation codes with expiry, attempt limits, and resend throttle; a domain allow-list on Branding so only organization addresses may self-register; daily prune of unconfirmed registrations older than 72 hours.
- Integration hub (Phase 8.3): connector registry for Teams, Slack, Google Drive, SharePoint/OneDrive, Gmail/Outlook, and generic HTTP; post-approval actions (upload, email, upsert, signed webhook) with encrypted credentials, test connection, retry/dead-letter, and replay; REST request-type schemas, cursor pagination, Idempotency-Key, `request-types:read` scope, and OpenAPI at `/api/v1/openapi.json`.
- Form builder refinements (Phase 6B): date and date-time fields now enforce their configured minimum and maximum as inclusive bounds, publishing rejects a choice default outside its option list or an inverted date range, the builder preview renders real controls and honors visibility/required rules against sample values, an advisory draft-checks panel lists schema problems before saving (choice fields without options, inverted ranges, defaults missing from options, conditions pointing at missing earlier fields, calculated fields with no sources), conditional-rule value pickers offer the referenced choice field's options, and the builder autosaves per browser with restore/discard so a lost tab does not lose unsaved edits.

### Changed

- Help centre related-guide links now open the matching in-app article instead of a raw `.md` file. Definition terms are heading links; guides a person cannot open are shown as plain text instead of a forbidden page. User-facing copy no longer mentions source folders, framework names, or internal field keys.
- Branding settings page redesigned for clarity: the form is grouped into "Application identity" and "Login page" cards with context subtitles, uploads show inline thumbnails with one-click undo ("Keep current … instead"), removal states say what will happen on save, custom CSS moved under an Advanced disclosure, and the live preview gained a sticky panel with Login and Browser tabs showing both branding surfaces before saving.
- Form builder bounds are now per field type: date and date-time fields use calendar date pickers labelled "Earliest/Latest date requesters can choose" with a plain-English summary of the allowed window, number and currency fields keep numeric minimum/maximum boxes, and other types show no bounds controls. Date bounds are stored as dates (no longer cast to numbers), and limits that are not real dates are rejected at save/publish with a clear message plus an inline draft warning — closing the trap where numeric limits made every date invalid.
- Notification channel interface with always-on in-app and email delivery, optional Microsoft Teams, Slack, WhatsApp, and Telegram (enable, encrypted credentials, test, identity mapping, signed chat approve, WhatsApp template mapping, email copy overrides, delivery receipts), installable PWA with browser push, and offline-safe draft editing. SMS remains interface-only.
- Branding settings for application name, meta description, application logo, and favicon, applied on login, the welcome page, and the signed-in chrome.
- Request collaboration: followers with explicit view access and per-request notification preferences, @mentions, threaded replies, a 15-minute comment edit window with append-only history, rate-limited reminders with optional quiet hours, out-of-office schedules with fallback approvers, future-dated delegation, and requester-visible expected next step plus estimated due time.
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

- Login background, logo, and favicon files are served from `/branding/assets/{file}` so a saved login background is visible without a public storage symlink.
- The login background is shown on narrow screens behind the sign-in form, not only in the large-screen split panel.
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
