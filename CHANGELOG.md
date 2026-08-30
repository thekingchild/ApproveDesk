# Changelog

All notable changes to ApproveDesk are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Changed

- Announcements now appear only on Dashboard. Long messages start as a compact excerpt with accessible **Show more / Show less** controls, and Show once messages are consumed only when Dashboard renders them.
- Announcement authoring now provides clickable controls for bold, italic, underline, bulleted and numbered lists, and safe hyperlinks while retaining server-side rich-text sanitization.
- The Branding application-name field is now presented as **Company name** and is used consistently throughout the signed-in app and printable approval reports. Reports always show the company name beside an optional bounded logo and wrap long names safely.
- The desktop sidebar can be collapsed and restored with a visible, keyboard-labelled control. The preference persists in the browser; the mobile drawer remains unchanged.

### Fixed

- Long company names no longer widen or displace the signed-in navigation menu.

## [2.8.0] - 2026-08-30

### Added

- System-owner announcements for all active users or selected roles, with Sticky, Acknowledge, and Show once display modes, sanitized rich text, publish/archive/republish controls, and an accessible global in-app banner.
- An owner-curated application-theme catalogue with immutable built-in palettes, WCAG contrast-checked custom palettes, enabled/default safeguards, and a per-user palette choice independent from Light, Dark, or System appearance mode.

### Security

- Announcement and theme administration is restricted to the singleton System owner; holding the legacy `super-admin` role alone does not grant access. Theme output uses fixed validated color variables and announcements are sanitized before storage.

### Changed

- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.8.0** as the current single-company release.

## [2.7.5] - 2026-08-29

### Added

- **Show public landing page** on **Settings → Branding** lets administrators enable or disable the drop-in marketing website without changing its files.

### Changed

- The site address is login-first by default: guests are sent to the branded Login page and signed-in people to Dashboard. Enabling the public landing page restores the existing marketing home, its account-aware header, and its static files.
- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.7.5** as the current single-company release.

## [2.7.4] - 2026-08-29

### Fixed

- A fresh cPanel/Plesk zip no longer returns HTTP 500 on `/install`. First-run boot now writes `APP_KEY` and uses file session/cache before cookies start, and settings reads no longer require the cache table.
- After installation, a failed settings read fails closed instead of silently disabling 2FA, Turnstile, and related controls.
- The public installer keeps `APP_DEBUG` off for the whole first-run wizard.
- The installer Welcome **Continue** control opens database setup instead of posting empty credentials to the save step.
- Choosing MySQL now migrates MySQL. The wizard no longer keeps the example file’s SQLite connection after you save database settings.
- Choosing a custom SQLite file updates the running installer connection before migrations, except the PHPUnit in-memory database.
- MySQL passwords that contain quotes, `#`, spaces, or `$` round-trip through `.env` instead of being truncated or interpolated.
- Vite, Livewire, and Flux scripts opt out of Cloudflare Rocket Loader with `data-cfasync="false"` placed before `src`. The deployment guide documents Full (strict) SSL, dynamic-route cache bypasses, and the recommended app-hostname rule.

### Changed

- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.7.4** as the current single-company release.

## [2.7.3] - 2026-08-28

### Added

- Professional approval reports for every request, with a branded A4 preview, browser printing, PDF download, request details, visible custom fields, attachment manifest, workflow progress, and the recorded action history. Report access follows the request policy and sensitive-field rules.

### Changed

- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.7.3** as the current single-company release.

## [2.7.2] - 2026-08-27

### Added

- Application timezone is read from `APP_TIMEZONE` (IANA name, default UTC) instead of a hardcoded UTC value.
- MySQL promote for verified in-app database snapshots. Host panel backups stay recommended.
- GitHub Actions MySQL 8 test job alongside the existing SQLite job.
- Schema test that every index and foreign-key name is 64 characters or fewer so MySQL/MariaDB can migrate.

### Changed

- Google and Microsoft new accounts follow the same registration domain list as password sign-up. Existing accounts can still link those providers.
- Analytics and custom code on Branding can be changed only by the System owner after confirming their password and second factor. Branding managers cannot add or remove it.
- Verify and promote refuse a snapshot whose file no longer matches the recorded size and checksum, including remote copies. File archives must match the stored file list exactly.
- Webhooks, integration connectors, web-push endpoints, and custom S3 backup endpoints must be public HTTPS. Private S3/MinIO needs `BACKUP_ALLOW_PRIVATE_S3_ENDPOINT=true`. Connector failures no longer store response bodies.
- Permission cache is not flushed until the `cache` table exists, so `composer install` / `package:discover` no longer fail on an empty SQLite memory database.
- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.7.2** as the current single-company release.

### Fixed

- Push subscription uniqueness now uses a SHA-256 `endpoint_hash` instead of indexing the TEXT endpoint, so MySQL/MariaDB can migrate (error 1170).
- Database dumps quote the bare table name, not `database.table`, so MySQL `SHOW CREATE TABLE` does not look for `approvedesk_test.approvedesk_test.activity_log`.
- Database snapshots now include the local destination before capture, and promoting an older snapshot restores its missing destination before keeping the promoted snapshot in the list.
- Both v2.1 upgrade tests roll back every later migration before loading their fixtures, so MySQL removes all dependent tables before the older request and workflow schema.
- Integration hub delivery list loads `reference_number` instead of a non-existent `reference` column.
- Shortened two MySQL-illegal index names (`sfv_submission_definition_unique`, `wsr_type_active_priority_index`) so a fresh MySQL/MariaDB install can migrate.
- Add a standalone `template_id` index before replacing the workflow-step unique, so MySQL does not refuse the drop (error 1553).
- Add a standalone `template_version_id` index so rolling back workflow versions on MySQL does not refuse the unique drop (error 1553) and leave later tests without `users.phone`.
- Add a standalone push-subscription `user_id` index so MySQL can replace or roll back the device uniqueness index without error 1553.
- Relaxed the business-calendar “completes quickly” bound so parallel PHPUnit is not flaky on a busy runner.

## [2.7.1] - 2026-08-26

### Added

- Backup snapshots are named with the date and time they were taken, listed newest first, and shown in a Date and time column. Restore history records who promoted which snapshot.

### Changed

- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.7.1** as the current single-company release.

### Fixed

- Promoting a database snapshot on SQLite no longer fails on older dumps that start with `SET FOREIGN_KEY_CHECKS`.

## [2.7.0] - 2026-08-26

### Added

- Custom feature-oriented roles on **Administration → Roles & permissions**, with protected built-in roles, owner-delegated role managers, a strict permission ceiling, cloning, archive/restore, and append-only role-change history.
- Pickable daily database and files backup times, with the application timezone, next-run times, and separate proof of the last scheduled backup.
- Read-only `php artisan ops:scheduler-check`, exact scheduler heartbeat time on Operations, and a shared lock so database and files backups cannot overlap.

### Changed

- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.7.0** as the current single-company release.
- Backup, operations, updates, branding, and related settings screens use feature permissions. Existing Administrator access stays effective.

## [2.6.0] - 2026-08-26

### Added

- Protected System owner created by the installer. Other people cannot see, edit, deactivate, anonymize, or recover that account. Historical request attribution stays visible.
- Owner-only account recovery: invalidate a password and email a reset link, or remove authenticator, recovery codes, and passkeys. Both sign the person out everywhere.
- Optional active-user limit on Administration → Users. Unlimited until the System owner sets a number. Inactive accounts do not count.
- Server-admin `owner:assign {email}` command to fill a missing owner after upgrade. It will not overwrite an existing owner.

### Changed

- Delegated administrators can no longer grant the Administrator role or set another person’s password from user edit.
- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.6.0** as the current single-company release.

## [2.5.5] - 2026-08-26

### Added

- Optional second-factor enforcement for signed approval URLs: when enabled, a valid link still requires the named approver to sign in and prove an authenticator, recovery code, or passkey in the current browser session before approving. Existing installations retain the previous link behavior until an administrator turns the option on.
- Optional analytics and custom code on **Settings → Branding**. Administrators can paste a tracking snippet or JavaScript that is added to login, the signed-in application, and the public home page. The field is empty until saved.

## [2.5.4] - 2026-08-24

### Added

- In-app database and file backups (Phase BK): **Administration → Backups** takes separate database and file snapshots, stores them on the local disk and optional S3-compatible or Google Drive destinations, retains 14/7 days by default without pruning the last success, and promotes only after a check. Host backups stay recommended.
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

- Help centre related-guide links now open the matching in-app article instead of a raw `.md` file. Definition terms are heading links; guides a person cannot open are shown as plain text instead of a forbidden page. User-facing copy no longer mentions source folders, framework names, or internal field keys. Safe `https` links (for example Google Cloud) stay clickable.
- The Backups Help article now includes the Google Drive refresh-token steps (Cloud Console, Drive API, OAuth Playground, folder id, **Save Drive destination**, then **Test**).
- Backups list shows each snapshot’s size plus Database and Files totals. **Remove local** (or **Remove local copies (keep remotes)**) deletes the on-server file when a remote replica exists; Verify, Download, and Promote fetch from the remote.
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
