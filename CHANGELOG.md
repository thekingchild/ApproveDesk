# Changelog

All notable changes to ApproveDesk are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- CI runs the test suite on MariaDB 10.6 as well as MySQL 8.
- `PerformanceSeeder` builds a large local dataset for performance review. It never runs outside the `local` environment.
- Code review plan (`REVIEW_PLAN.md`) and findings register (`docs/review/FINDINGS.md`).

### Fixed

- **Condition branches follow their step.** In the workflow builder and on the Workflow templates list, moving or duplicating steps no longer re-points a condition's true/false branch at a different step, and deleting a branch's target asks for a new one instead of silently completing the workflow. The builder now shows the step each branch goes to, offers the operator list, and lists anything that blocks saving.
- **Re-saving a template in the builder no longer breaks "in list" conditions.** Lists stay lists, and versions already damaged by the old behaviour (for example `high, urgent` stored as one value) route by the list again. Extra rules and the any/all mode of multi-rule conditions are kept.
- Conditions and selection rules on unknown fields are refused when saved or published, instead of publishing and then failing every request that reaches them.
- **`metadata.KEY` rules now work on real requests.** Selection rules and conditions on `metadata.amount` read the form field `amount`, which is what Explain routing and simulation already showed. Before, they never matched a real request. **Upgrade note:** review active rules and conditions that use `metadata.*`; requests submitted after the upgrade follow them.
- Form visibility and required rules that check a multi-select field no longer crash the form; they match when the field contains the value.
- **A request that cannot be sent for approval is kept as a draft.** Submitting no longer shows an error page when, for example, nobody can approve a step. Your work, including attachments, is saved as one draft (retrying no longer creates another), opened, and the reason is shown. Answering an information request posts your reply only if the request actually goes back for approval.
- On custom forms with more than one file field, each field now keeps its own uploads; they were linked to the wrong field.
- Required custom fields are checked on every submit, including the API and resubmitting a returned request.
- API: `POST /api/v1/submissions` with `submit: true` is all-or-nothing, and both submit endpoints return 422 with the reason instead of a server error or a stranded Pending request. Answering an information request through `POST /api/v1/submissions/{id}/submit` requires a `comment`.
- **Delegations can be taken back.** The request page offers **Take back** to the person who delegated a step; deleting or shortening an out-of-office period hands its steps back; and a delegation to someone who is later deactivated no longer locks the original approver out. Each early end is recorded in the history.
- Expired delegations are no longer deleted every night, so evidence packs keep who acted as delegate and when.
- The nightly clean-up of unconfirmed sign-ups no longer deletes established accounts whose owners changed their email address; it only removes self-registrations that never confirmed their code.
- **Editing a template on the Workflow templates list no longer weakens it.** Saving there used to reset each step's completion rule to its type's default (an "All assignees" step became "first response", so one approval completed the request) and removed custom outcomes. Both are now kept unless you change that step's type.
- New database indexes for the unread-notification count, workflow step completion checks, and date-based request lists.
- Faster dashboards, approval inbox, analytics, request page, and notifications: they no longer run extra database queries for every row (for example the notifications page went from 111 to 29 queries for 20 notifications). Settings are read from one cached entry per request instead of one per setting.
- A missing setting no longer remembers the first default value any screen asked for.
- Delegation, out-of-office, and auditor-access start and end times are now read in the viewer's own time zone (shown under the fields) instead of the server's, so windows no longer shift by hours for people in another zone.
- Database backups are taken as one consistent point-in-time snapshot, so a decision recorded during a backup can no longer leave a request's status and its history out of step in the backup.
- Bulk updates and deletes of workflow history are refused by the application, not only single-record changes.
- **Database promote on MySQL/MariaDB is now recoverable.** ApproveDesk takes and verifies a safety backup first, stays in maintenance mode until the restored database passes its check, and automatically puts the safety backup back if the restore fails (staying in maintenance mode if even that fails). The confirmation warns how many later workflow actions will be removed, and Restore history records the safety backup and that count. New nullable `backup_restore_events` columns.
- On MariaDB, requests waiting for an approver chosen in a form field now appear in that approver's dashboard and API lists.
- Deleting a user can no longer alter workflow history. Request history references are now protected in the database, people who appear in delegations or comments count as having history (so they are anonymized rather than deleted), and the unconfirmed-registration clean-up skips anyone who appears in history.
- Withdrawing a request now goes through the workflow engine. It is recorded in the request history, and it can no longer overwrite a decision that was completed at the same moment.
- A delegate now fills the delegator's place on a step. On **All** and **Quorum** steps the two can no longer count as two approvals, and the request history and approval report show decisions made **on behalf of** someone. New nullable `workflow_actions.on_behalf_of_id` column; existing history is unchanged.
- When a request is returned for revision and resubmitted, everyone on that step now decides again on the revised content. Approvals given before the return remain in the history but no longer complete the step.
- A decision is applied only to the step the approver was looking at. Double-clicks, retried requests, and reused email or chat approvals no longer approve the next step when the same person is assigned to it. The API accepts an optional `step_id` for the same protection.
- Approvers resolved dynamically (requester's manager, department manager, person picked on the form, connected system) now see the decision panel and delegation on the request page.
- Fresh installs on MariaDB 10.6–10.9 no longer fail at the delegations migration, and required timestamp columns (for example a workflow's start time or a legal hold's placement time) are no longer silently reset on row updates on those versions. MySQL and SQLite schemas are unchanged.
- The test suite refuses to run when configuration or routes are cached, or when the database is not an isolated test database. Previously a cached config made tests run against, and reset, the local development database.

### Changed

- The **Create comments**, **Send reminders**, **Withdraw requests**, **Export audit evidence**, and **Manage auditor access** permissions are now enforced. An upgrade migration grants them to every existing role that already had the ability, so nobody loses access; removing one from a role now removes that ability. Requesters can always comment on, remind about, and open their own requests.
- Browser and accessibility tests now block merges in CI. They had been failing unnoticed since v2.8.3 because the CI server and test process used different databases.

### Security

- **Microsoft sign-in can no longer take over accounts ("nOAuth").** With a multi-tenant Microsoft setup (`common`/`organizations`), the email Microsoft reports is not verified, so ApproveDesk no longer uses it to create accounts or connect existing ones; already-connected accounts keep working. Pin the Microsoft tenant to your directory ID to allow creation and connection. Social sign-in now also requires the account's authenticator code, and passkey accounts must use their passkey.
- Changing another person's Slack, Teams, or Telegram identity now follows the same rule as changing their email (administrators and people with more access only by the System owner), and every change is logged.
- S3 backup destinations are pinned to the addresses that passed the outbound check, and virtual-hosted bucket names (`bucket.endpoint`) are checked too, so DNS changes cannot redirect backups to internal hosts.
- Request titles, comments, and names in notification emails are shown as plain text, so they can no longer turn into links inside a genuine ApproveDesk email.
- Saving owner analytics/custom code requires a password confirmation within the configured confirmation window, not one from any earlier point in the session.
- Mentioning someone who cannot already open a request now requires permission to add followers, so followers can no longer share a request with anyone by mentioning them.
- The API accepts only the requester's own department when creating a submission.
- Disposition reviews are decided once, under a lock, and — while separation of duties is on — not by the person who opened them.
- Teams and Slack one-click **Approve** now respects **Require sign-in and a second factor for approval links**: while it is on, cards open the confirmation page and older card buttons stop working. Slack approvals are accepted only from the approver's own linked Slack account.
- Following a request no longer turns temporary access (an auditor grant or a delegation) into permanent access, and read-only auditors can no longer follow requests.
- User import's **Update the existing account** mode now follows the same rules as editing a user by hand (Update users permission; administrators only by the System owner), and the analysed preview can no longer be altered in the browser before it is applied.
- Administrators can no longer change the email of, deactivate, or anonymize peer administrators or anyone holding permissions above their own; only the System owner can. Previously an administrator could redirect a stronger account's email and reset its password.
- Publishing from the workflow builder now requires the **Publish workflows** permission, as on the Workflow templates screen.
- Custom outcome keys are limited to lowercase letters, numbers, and underscores, and are rendered safely, so a key can no longer inject script into the approver's decision buttons. Unknown decisions are refused instead of being recorded as Approve.
- **Separation of duties:** requesters can no longer approve, decide, or be delegated their own requests. This is a new setting under **Settings → Approval settings**, on by default. If a step would be approved only by the requester, the request stops with a message naming the step until an administrator changes who approves it. Requests already in progress follow the rule immediately.
- Rich text (request bodies, announcements, and the API `body` field) can no longer carry script through legacy raw-text tags such as `<xmp>` or `<noembed>`. The sanitizer now rebuilds output from an allow-list, and stored content is re-sanitized when displayed.
- The rich-text editor (Tiptap 3.32, ProseMirror) is updated to fix a paste-handling XSS and prototype-pollution issues reported by `npm audit`. Build tooling (`concurrently`) moved to development dependencies. `npm audit` reports no vulnerabilities.

## [2.8.6] - 2026-10-07

### Changed

- General Request no longer autosaves every 30 seconds. Use **Save draft** to keep work without submitting.
- README, `.env.example` `APP_VERSION`, the Updates-page fallback, application configuration, and this changelog treat **v2.8.6** as the current single-company release.

### Security

- Laravel, CommonMark, and Flysystem are updated so the release passes Composer’s security audit.

### Fixed

- Request history no longer displays raw HTML tags or expands repeated body text for every edit. Body comparisons are collapsed by default, with safely formatted Before/After versions available under **View body changes**; existing audit records remain unchanged.
- System owners can save **Settings → Branding → Advanced: analytics and custom code**. Missing password confirmation now opens the confirmation page and keeps the snippet instead of failing silently.
- Uploaded favicons appear on the approval report preview (browser tab, and as the report mark when no logo is saved). ICO favicons no longer break the Branding preview.

## [2.8.5] - 2026-09-05

### Added

- Administrators can configure outbound mail (SMTP, Amazon SES, Postmark, Resend, Sendmail), session lifetime, expire-on-close, and Google, Microsoft, or GitHub sign-in from **Settings → System**. Secrets are encrypted, masked, and submitted outside Livewire.
- Profile display-name changes wait for a different administrator’s approval. People can upload a re-encoded JPEG, PNG, or WebP profile photo.
- Installed-app (PWA) icons follow the company logo, with an ApproveDesk-owned fallback instead of the Laravel starter mark.

### Fixed

- The original submitter can edit a Returned request and respond to an Awaiting information request, including files when asked.

### Changed

- GitHub Actions no longer deploys to staging or production. Quality CI remains; tags publish a GitHub Release only.
- Notification channels Help now separates ApproveDesk email copy from Meta-owned WhatsApp wording and provides setup steps, sample Meta templates, and matching placeholder bindings for every supported notification kind.
- README, `.env.example` `APP_VERSION`, the Updates-page fallback, application configuration, and this changelog treat **v2.8.5** as the current single-company release.

## [2.8.4] - 2026-09-01

### Added

- System owners can configure the approval ID prefix, separator, year/month patterns, digit length, and sequential or random generation from Approval settings; the backward-compatible default remains `SUB-YYYY-00001`.
- Sequential approval IDs use locked period and digit-length counters. Random IDs reserve a candidate before assignment and retry collisions, including IDs from deleted requests. Sequential generation stops instead of overflowing the chosen digit length.
- An additive `approval_reference_counters` table stores those counters. Existing `reference_number` values are never rewritten.

### Changed

- Printable approval reports and downloaded PDFs now use the eight supplied status-specific image stamps for Draft, Pending, In review, Approved, Rejected, Returned, Awaiting information, and Withdrawn instead of the generated text stamp.
- Approval settings Help now documents owner-only ID formatting, sequence resets, random generation, unchanged historical IDs, and printable status-stamp behavior.
- README, `.env.example` `APP_VERSION`, the Updates-page fallback, application configuration, and this changelog treat **v2.8.4** as the current single-company release.

## [2.8.3] - 2026-09-01

### Added

- The optional public landing page now ships the completed static ApproveDesk marketing design, including server-injected account actions, responsive styling, supplied brand artwork, favicon and social-sharing assets, SEO metadata, and production footer links. It remains a build-free `website/` drop-in and stays disabled by default.
- Printable approval reports show the persisted request status as an accessible, status-colored stamp in the upper-right heading area. The marker remains visible in preview, browser print, and PDF without covering report evidence.

### Security

- Help articles now use explicit signed-in, permission-based, or singleton System-owner audiences instead of a broad administrator heuristic. Index cards, direct routes, compact menus, Markdown links, related links, contextual links, and screen actions enforce the same decision.
- Protected recovery, capacity, owner-grant, announcement/theme management, and custom-code procedures now live in a System-owner-only guide. Shared guides no longer expose those procedures to delegated administrators or ordinary users.
- Branding and approval settings have separate guides so a custom role can read only the procedure for the screen it is allowed to open.

### Changed

- README, `.env.example` `APP_VERSION`, the Updates-page fallback, application configuration, and this changelog treat **v2.8.3** as the current single-company release.
- No database migration or new configuration key is required.

## [2.8.2] - 2026-08-31

### Security

- Role grants, including built-in roles, cannot exceed the editor's own access. A non-owner cannot change their own roles from user administration. Saving a person's profile leaves roles they already hold unchanged, including roles the editor cannot grant.
- Web-push delivery pins destinations and does not follow redirects.
- Reading one submission through the API requires the `submissions:read` ability in addition to model authorization.
- User spreadsheet import refuses oversized or highly compressed workbooks before preview and accepts at most 5,000 data rows.
- Analytics CSV and Excel exports keep text as text so spreadsheet applications do not treat request content as formulas.

Installer ingress allow-listing and `storage/app/installed.lock` remain operational verification on the deployed host. They are not application findings closed by this patch. No migration or new configuration key is required.

### Changed

- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.8.2** as the current single-company release.

## [2.8.1] - 2026-08-30

### Changed

- Announcements now appear only on Dashboard. Long messages start as a compact excerpt with accessible **Show more / Show less** controls, and Show once messages are consumed only when Dashboard renders them.
- Announcement authoring now provides clickable controls for bold, italic, underline, bulleted and numbered lists, and safe hyperlinks while retaining server-side rich-text sanitization.
- The Branding application-name field is now presented as **Company name** and is used consistently throughout the signed-in app and printable approval reports. Reports always show the company name beside an optional bounded logo and wrap long names safely.
- The desktop sidebar can be collapsed and restored with a visible, keyboard-labelled control. The preference persists in the browser; the mobile drawer remains unchanged.
- README, `.env.example` `APP_VERSION`, and this changelog treat **v2.8.1** as the current single-company release.

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
