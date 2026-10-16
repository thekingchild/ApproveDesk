# Review Findings Register

Companion to [REVIEW_PLAN.md](../../REVIEW_PLAN.md). One entry per finding; format and severity scale are defined in REVIEW_PLAN §2.

## Baseline (Wave 0 — 2026-10-12, commit `4131fa1`, v2.8.6)

| Check | Result |
|---|---|
| `php artisan test --parallel` (SQLite in-memory, 8 workers) | **949 tests — 947 passed, 2 skipped**, 4,863 assertions, 2 m 20 s |
| Coverage (CI run 37644765296, v2.8.6) | `app/` **85.66%** (9,905/11,563) · `WorkflowEngine` **97.10%** (368/379) — gate 97% |
| `composer types:check` (Larastan level 7) | Pass, 0 errors · **87 baseline entries** |
| `pint --test` | Pass |
| `composer audit --no-dev` | Reported 4 advisories, but **false positive**: plain `composer audit` checked a stale local `vendor/`. `composer audit --locked` on `main` is clean → RV-SEC-01 closed |
| `npm audit --omit=dev` | **Fail** — 28 (2 critical, 3 high, 23 moderate) → RV-SEC-02 |
| Routes | 145 (`route:list --json` snapshot used by the SEC track) |
| CI browser/a11y job | **Failing on every `main` run since ≥ 2026-08-31**, masked by `continue-on-error` → RV-TEST-02 |
| MySQL 8 / MariaDB 10.6 locally | Not run — Docker socket not accessible to this user. MySQL runs in CI; MariaDB 10.6 job added to CI in Wave 0 (results on first PR run) |

### Query scaling probe (rows 3 → 30, SQLite, `preventLazyLoading` on)

Query count must stay flat as rows grow. Pages marked ✗ grow with data (row-level N+1).

| Page | n=3 | n=30 | |
|---|---|---|---|
| Dashboard (admin) | 43 | 70 | ✗ RV-PERF-01 |
| Dashboard (approver / submitter) | 25 / 21 | 25 / 21 | ✓ |
| Approvals inbox | 21 | 48 | ✗ RV-PERF-02 |
| Submissions index (submitter / admin) | 20 / 21 | 20 / 21 | ✓ |
| Submission detail | 52 | 106 | ✗ RV-PERF-03 (+ lazy `Comment::commentable`) |
| Approval report | 23 | 23 | ✓ |
| Analytics | 23 | 50 | ✗ RV-PERF-04 |
| Admin users / records | 23 / 17 | 23 / 17 | ✓ |
| Notifications | 37 | 103 | ✗ RV-PERF-05 |
| Workflow templates / request types | 16 / 14 | 16 / 14 | ✓ |

### Large dataset

`database/seeders/PerformanceSeeder.php` (local-only, not in `DatabaseSeeder`) drives every transition through `WorkflowEngine`. Throughput ≈ 0.2 s/submission on SQLite, so the plan's 20k target takes over an hour. The Wave 0 dataset in `database/perf.sqlite` holds **312 users, 2,857 submissions, 4,230 workflow actions, 2,117 comments** (run stopped at a 10-minute tool limit; the last submission may lack a workflow instance):

```bash
touch database/perf.sqlite
DB_DATABASE=$PWD/database/perf.sqlite php artisan migrate --seed
DB_DATABASE=$PWD/database/perf.sqlite PERF_USERS=300 PERF_SUBMISSIONS=3000 php artisan db:seed --class=PerformanceSeeder
```

---

## Findings

### RV-TEST-01 — Tests silently run against the developer database when config is cached
- Severity: **S2** · Status: **Fixed** (branch `feature/rv-wave0-baseline`)
- Location: `tests/TestCase.php`
- Evidence: a stale `bootstrap/cache/config.php` (1 Sep, `APP_ENV=local`) made PHPUnit ignore `phpunit.xml`. Parallel runs used `database/database.sqlite_test_N`; a serial run used `database/database.sqlite` itself, and `RefreshDatabase` ran `migrate:fresh` on it. The local dev database was reset during Wave 0 (the 1 Sep pre-release backup also contains 0 users / 0 submissions, so it is believed to have been empty). Cached routes also hid the newer `pwa.icon` route, giving false 500s.
- Impact: any contributor with cached config can destroy local data by running tests; results are not trustworthy.
- Fix: `TestCase::createApplication()` refuses to run when config/routes are cached, when `APP_ENV` ≠ `testing`, or when the database is not in-memory SQLite / a `*test*` MySQL/MariaDB database. Verified: passes normally; fails before any migration with `config:cache` present.

### RV-TEST-02 — Browser and accessibility tests have been failing for 6+ weeks unnoticed
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-test-browser-ci`; CI wiring fixed, `continue-on-error` removed — closes when the PR's `browser` job is green). Flaky draft-flow waits remain open.
- Location: `.github/workflows/ci.yml` (`browser` job, `continue-on-error: true`), `tests/Browser/*`
- Evidence: all 5 Dusk tests (`AccessibilityResponsiveTest` ×2, `RequesterApprovalFlowTest` ×3) fail with `TimeoutException` on every `main` run from 2026-08-31 to v2.8.6.
- Impact: the plan's accessibility exit gate (IMPLEMENTATION_PLAN §3.1) and the wizard/approval-modal browser coverage (AGENTS.md) are effectively off.
- Diagnosis (2026-10-16): all 5 tests **pass locally** (Chrome/ChromeDriver 155, repeated runs), so the failure is CI-environment specific. In CI the login page renders, then every authenticated page times out, i.e. Dusk's `loginAs()` does not take effect on the server. `php artisan serve` blanks non-allowlisted environment variables for its workers when `.env` exists and restarts when Dusk swaps `.env`, so the server and test process can disagree on configuration. Separately, `test_requester_can_save_reopen_and_submit_a_draft` is flaky locally (≈3 in 9 runs: "element click intercepted" — fixed `pause()` waits).
- Fix: Wave 1 — give the CI job one unambiguous database path and run the server with `--no-reload`, print the server log on failure, replace `pause()` waits with state waits, then remove `continue-on-error`.

### RV-SEC-01 — Composer advisories (false positive)
- Severity: — · Status: **Closed — not a defect** (2026-10-16)
- Resolution: `composer audit` without `--locked` audits installed packages. The local `vendor/` was behind `composer.lock`; the lock on `main` already contains CommonMark 2.10.3, Flysystem 3.36.0 and Laravel 13.35.0, and `composer audit --locked` reports nothing. CI installs from the lock, so CI was never affected. Lesson: baseline audits must use `--locked` (or run after `composer install`).
- Original (incorrect) report follows for the record.
- Evidence: `league/commonmark` ≤ 2.10.1 (high: GFM quadratic DoS; medium: `DisallowedRawHtml` bypass); `laravel/framework` < 13.30.0 (low: debug-page XSS); `league/flysystem` ≤ 3.35.2 (low: path normaliser bypass).
- Impact: CommonMark only renders bundled Help Markdown (`App\Support\HelpArticle`), so untrusted input does not reach it today. CI runs `composer audit` as a required step, so every PR will fail until updated.

### RV-SEC-02 — npm advisories in browser-shipped editor and misplaced build tooling
- Severity: **S2** · Status: **Fixed** (branch `feature/rv-sec-deps`, v2.8.7)
- Evidence: `@tiptap/*` (high: `__proto__` merge, ReDoS) and `prosemirror-view` (high: XSS in paste handling) are bundled into the rich-text editor; `concurrently` → `shell-quote` (critical) and `source-map-js` (high) are build/dev tooling, but `concurrently` is listed under `dependencies`.
- Impact: client-side XSS on paste into the editor (server-side sanitising still applies on save).
- Fix: Tiptap 3.30.1 → 3.32.0 (with patched `prosemirror-view`); `concurrently` moved to `devDependencies`. `npm audit` (all deps) reports 0. Dusk suite 5/5 locally, repeated.

### RV-PERF-01 — Admin dashboard issues ~1 query per submission
- Severity: S3 · Status: **Fix in review** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/dashboard.blade.php` · Evidence: 43 → 70 queries for 3 → 30 submissions.

### RV-PERF-02 — Approvals inbox issues ~1 query per pending item
- Severity: S3 · Status: **Fix in review** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/approvals/inbox.blade.php` · Evidence: 21 → 48 queries.

### RV-PERF-03 — Submission detail issues ~2 queries per comment; lazy-loads `Comment::commentable`
- Severity: S3 · Status: **Fix in review** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/submissions/show.blade.php` · Evidence: 52 → 106 queries for 3 → 30 comments; lazy-load violation `Comment::commentable`.

### RV-PERF-04 — Analytics query count grows with submissions
- Severity: S3 · Status: **Fix in review** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/admin/analytics.blade.php`, `app/Services/AnalyticsService.php` · Evidence: 23 → 50 queries.

### RV-PERF-05 — Notifications centre issues ~2.4 queries per notification
- Severity: S3 · Status: **Fix in review** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/notifications/index.blade.php` · Evidence: 37 → 103 queries.

### RV-TEST-03 — Query budget tests cannot detect row-level N+1
- Severity: S3 · Status: **Fix in review** (`QueryScalingTest`: 9 pages, equal query counts at two dataset sizes) · Location: `tests/Feature/QueryBudgetTest.php`
- Evidence: fixed budgets measured at 5–20 rows; none of RV-PERF-01…05 trips them.
- Fix: Wave 2 — assert equal query counts at two dataset sizes per page, and cover the five pages above.

### RV-LAR-01 — No lazy-loading / strict-model guard outside production
- Severity: S3 · Status: **Fix in review** (`Model::preventLazyLoading(! app()->isProduction())`; the three violations the suite surfaced are fixed) · Location: `app/Providers/AppServiceProvider.php`
- Fix: `Model::shouldBeStrict(! app()->isProduction())` (or at least `preventLazyLoading`) after RV-PERF-03 is fixed.

### RV-TEST-04 — `WorkflowEngine` coverage is only just above its gate
- Severity: S3 · Status: Open — **decision pending** (REVIEW_PLAN §11 Q4) · Evidence: 97.10% vs a 97% gate; AGENTS.md states 100%.

### RV-DATA-01 — MariaDB 10.6 is supported but untested
- Severity: S2 · Status: **Fixed** (CI job added in PR #23) · Location: `.github/workflows/ci.yml` (`mariadb` job)
- First run: 882 of 949 tests fail, all from one cause → RV-DATA-02.
- Second blocker (RV-TEST-05): after RV-DATA-02 the job stalled in `InstallerTest` for 30+ minutes. A CI watchdog showed connection A idle inside an open transaction and connection B's `drop table …` (migrate:fresh) "Waiting for table metadata lock". Cause: `InstallerTest::restoreTestingDatabase()` forced `database.default=sqlite` and purged only `mysql` before `RefreshDatabase` rolled back, so the rollback went to sqlite and the `mariadb` transaction leaked (on MySQL the purge happened to close it). Fixed on branch `feature/rv-test-mariadb-installer` by restoring and keeping the recorded testing connection.

### RV-DATA-02 — Migrations fail or silently corrupt timestamps on MariaDB 10.6–10.9
- Severity: **S1** · Status: **Fix in review** (PR #23, commit `d3b9803`)
- Location: 10 `Schema::create` blocks with `NOT NULL TIMESTAMP` columns (`timestamp('x')` with no `nullable`/`useCurrent`/`default`).
- Evidence: CI `mariadb` job — `1067 Invalid default value for 'expires_at'` on `create table delegations`. MariaDB < 10.10 defaults to `explicit_defaults_for_timestamp=OFF` (global-only, not settable per session before 10.10), so:
  - the **first** such column in a table silently becomes `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`;
  - any **second** one gets a zero-date default, which Laravel's strict mode rejects.
- Impact:
  - **Install-breaking (2+ columns):** `delegations` (starts_at, expires_at), `user_out_of_office` (starts_at, ends_at), `auditor_accesses` (starts_at, ends_at). A fresh install on MariaDB 10.6–10.9 — common on cPanel — stops at `2026_08_17_120000`.
  - **Silent overwrite (1 column):** `workflow_instances.started_at`, `workflow_actions.created_at`, `submission_nudges.scheduled_for`, `registration_otp_codes.expires_at`, `legal_holds.placed_at`, `ops_snapshots.recorded_at`, `ops_backup_verifications.verified_at` would be reset to "now" on every row update — e.g. `started_at` on every approval step, `placed_at` when a hold is released. Audit-evidence corruption.
  - No working MariaDB < 10.10 installation can exist today (installs fail at `delegations`), so changing these migrations creates no drift for real installs. MySQL 8, MariaDB ≥ 10.10 and SQLite are unaffected.
- Fix: `App\Support\SchemaTimestamps::required()` adds an explicit `DEFAULT CURRENT_TIMESTAMP` only on MariaDB with the setting off. The 7 released migrations were edited as a recorded exception (decision 2026-10-16, ADR 0004). Regression test `PortableTimestampColumnsTest` (static scan + MySQL/MariaDB column metadata). Closes when the `mariadb` CI job on PR #23 is green.

## Wave 1 findings (2026-10-16)

Sources: three read-only reviews (engine/audit, authorization, input-output/files/integrations), each backed by throwaway probe tests; every S1/S2 below was re-run or re-traced before logging. "Probe" = a failing-behaviour test reproduced it.

### RV-SEC-03 — Stored XSS through raw-text elements in the rich-text sanitizer
- Severity: **S1** · Status: **Fixed** (branch `feature/rv-sec-sanitizer`, `9facaa4`)
- Evidence: `sanitize('<xmp><img src=x onerror=alert(1)></xmp>')` returned a live `<img onerror>`; `<xmp><noembed>…</noembed></xmp>` survived the save-time and render-time passes. Reproduced end to end: submitter's payload executed on the approver's submission page, inbox preview, and approval report.
- Fix: rebuild output into a fresh DOM from the allow-list (all text nodes re-created, so always escaped); re-sanitize announcements and the API `body` on output. Tests for 10 raw-text payloads, idempotence, and stored announcements.

### RV-ENG-01 — Requesters can approve their own requests (no separation of duties)
- Severity: **S1** · Status: **Fix in review** (branch `feature/rv-eng-separation-of-duties`) — decided 2026-10-16: setting "Requesters cannot approve their own requests", **on by default**. The requester is removed from every resolved assignee set, self-delegation is refused, and the engine rejects any state-changing action by the requester. If the requester would be a step's **only** approver, routing stops with a message naming the step (decided 2026-10-16; an administrator "reassign step" tool is a separate follow-up, RV-FEAT-01).
- Evidence (probes): submitter listed on a User/Role step; department manager submitting for their own department; FormField step where the submitter picks themselves; delegation to the submitter. `WorkflowEngine::authorize()` (`app/Services/WorkflowEngine.php:239-268`) and `AssigneeResolver` never compare the actor with `submitter_id`.

### RV-DATA-03 — MySQL backup promote drops live tables before a non-atomic reload, with no pre-promote copy
- Severity: **S1** (data loss; owner-initiated) · Status: **Fix in review** (branch `feature/rv-data-promote-safety`). Shared hosting usually cannot create a scratch schema, so instead of `RENAME TABLE` the promote: enters maintenance mode → takes and verifies a safety backup → loads → verifies → reopens; any failure reloads the safety backup, and a failed rollback keeps maintenance mode on with a critical log naming the safety snapshot. Restore trail records the safety snapshot and discarded workflow actions; the confirmation warns of the loss beforehand
- Location: `app/Actions/Backups/PromoteDatabaseBackupAction.php:182-200`
- Evidence: `SET FOREIGN_KEY_CHECKS=0`, drop every table, `DB::unprepared($sql)`, then bring the app up and verify. A failure mid-load leaves a partial database; the SQLite path keeps a `.pre-promote` copy, the MySQL path does not. A successful promote silently discards newer `workflow_actions`, and the restore trail doesn't record how many.
- Fix: snapshot before dropping; load into a scratch schema and swap with `RENAME TABLE`; stay in maintenance until verified; record the live-vs-snapshot `workflow_actions` delta.

### RV-ENG-02 — Replayed or double-submitted actions approve the *next* step
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-eng-stale-step`; also closes RV-ENG-02's batch variant). `expectedStepId` is optional on the engine for backward compatibility; every production caller passes it. API clients opt in with `step_id`
- Evidence (probe): `processAction()` takes no expected step and acts on whatever `current_step_id` is after the lock. Pre-lock checks in `EmailApprovalController:60` and `ApproveSubmissionFromChatAction:190` race; API, Livewire `act()` and `BatchApproveSubmissionsAction:112-133` don't check at all. A user on steps 1 and 2 completes both with one double-click/retry.
- Fix: required `expectedStepId` on `processAction()`/`delegate()`, checked after the lock; pass it from every caller.

### RV-ENG-03 — One quorum/“All” seat counted twice (delegate + delegator)
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-eng-delegated-seat`). New migration adds nullable `workflow_actions.on_behalf_of_id` (restrict on delete); the engine counts distinct seats and treats a delegate's decision as the delegator's. Pre-existing delegate decisions have no seat recorded and keep the old counting · Location: `WorkflowEngine.php:481-489, 673-686`
- Evidence (probe): quorum 2 of {A,B,C}; A delegates to D, D approves; after expiry A approves → step complete without B or C.
- Fix: count approvals by seat (record on-behalf-of); treat “my delegate already decided” as a duplicate.

### RV-ENG-04 — Approvals survive return-for-revision
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-eng-review-round`; decided 2026-10-16: a return resets the step). `App\Services\Workflow\ReviewRound` counts only actions after the latest return on that step, in the engine, workbench, dashboard query, and request page. This reverses a previously tested behaviour (`ParallelWorkflowTest` updated) · Location: `WorkflowEngine::resume()` (`:692-723`), `isStepSatisfied`
- Evidence (probe): parallel {A,B}; A approves, B returns, submitter revises; B approves → complete. A never saw the revised content and is blocked as a duplicate.
- Fix: append a `resubmitted` system action; count approvals/duplicates only after the latest return on that step.

### RV-ENG-05 — Withdraw mutates the workflow instance outside the engine
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-eng-withdraw`; `WorkflowEngine::withdraw()` locks the instance first, re-checks it is open, appends a `withdraw` action, then cancels instance and submission) · Location: `app/Actions/Submissions/WithdrawSubmissionAction.php:57-64`
- Evidence: updates `workflow_instances` without a lock or an action row (breaks invariant 2); can overwrite a just-committed completion; locks submission→instance while the engine locks instance→submission (deadlock risk on MySQL).
- Fix: `WorkflowEngine::withdraw()` with lock, status re-check, appended `withdraw` action.

### RV-ENG-06 — Deleting a user rewrites `workflow_actions.delegated_to` via FK cascade
- Severity: **S2** (audit tampering) · Status: **Fix in review** (branch `feature/rv-eng-audit-fk`; new migration makes `delegated_to` and `instance_id` restrict-on-delete; evidence check covers delegations, on-behalf-of, and comments; prune skips users with evidence)
- Evidence (probe): `delegated_to … nullOnDelete()`; `hasPreservedAuditEvidence()` ignores `delegated_to`; `users:prune-unconfirmed` bulk-deletes with no evidence check. `instance_id cascadeOnDelete` is latent risk.
- Fix: new migration → `restrictOnDelete`; extend the evidence check; make prune skip users with evidence.

### RV-BUG-01 — Manager / department / form-field approvers see no action buttons on the request page
- Severity: **S2** · Status: **Fix in review** (with RV-ENG-01; regression test `test_dynamic_approvers_can_act_from_the_request_page`) · Location: `resources/views/pages/submissions/show.blade.php:171, 222, 239`
- Evidence (probe): `$step->assignedUsers()` is called without the submission, so dynamic assignees resolve to nobody and `canAct` is false.
- Fix: pass `$instance->submission`.

### RV-BUG-02 — Form-field approvers missing from dashboard and API lists on MariaDB (lead L1)
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-bug-json-assignee`; the field key is selected through the query grammar's JSON path, unquoted per driver; new test exercises the SQL path so the MariaDB job guards it). `External` assignees still have no SQL branch (their resolvers are PHP-only) — tracked as a known limitation · Location: `app/Services/Workflow/AssignedStepConstraint.php:71`
- Evidence: MariaDB `JSON_EXTRACT` returns the quoted string, so `key = '"reviewer"'` never matches; SQLite/MySQL 8 match. No test covers this branch. The constraint also has no branch for `External` assignees on any database.
- Fix: driver-aware unquote (or Laravel's `->` JSON path); add a feature test so the MariaDB job guards it.

### RV-SEC-04 — Admins can take over or wipe higher-privileged accounts (email change, anonymize)
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-sec-user-ceiling`). New `UserPolicy::manageIdentity` (non-owner: target is not admin/super-admin and within the actor's permission ceiling) guards email changes, activation, and anonymization; ordinary edits keep the existing "Roles you cannot remove" design. Not done: notify the old address on email change (hardening backlog) · Location: `app/Policies/UserPolicy.php:19-42`, `app/Actions/Users/UpdateUserAction.php:27,66`
- Evidence (probes): a non-owner admin (or custom role with `users.update`) changes the email of a user holding above-ceiling permissions, then resets the password; admin A anonymizes peer admin B (edit is refused, anonymize is not).
- Fix: enforce the admin/permission-ceiling rule in `UserPolicy::update` (inherited by anonymize and name decisions); notify the old address and re-verify on email change.

### RV-SEC-06 — User import "update" mode rewrites any user without `users.update`
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-sec-import-update`; per-row `update` policy plus admin-only-by-owner rule; `$preview` is `#[Locked]`) · Location: `app/Services/Users/UserImportService.php:233-243`; `pages/admin/users-import.blade.php` (`$preview` not locked)
- Evidence (probes): an importer sets a victim's `manager_id` to themselves and becomes approver of the victim's in-flight requester-manager steps.
- Fix: authorize `update` per row (with RV-SEC-04's rule); lock `$preview` or re-analyse on commit.

### RV-SEC-07 — Workflow builder publishes without `workflows.publish`
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-sec-builder`). Residual, by design for now: saving a template that has never been used still auto-publishes for `workflows.manage` holders (`UpdateWorkflowTemplateAction`); revisit with RV-SEC-13 · Location: `pages/admin/workflow-builder.blade.php:276-282`
- Evidence (probe): `confirmPublish()` authorizes only `update`.
- Fix: `authorize('publish')` in `requestPublish`/`confirmPublish` and inside `PublishWorkflowTemplateVersionAction`.

### RV-SEC-08 — Time-limited auditor or delegate access becomes permanent by following
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-sec-follow-access`; self-follow requires the `follow` policy; only followers added by someone else grant view) · Location: `app/Actions/Collaboration/FollowSubmissionAction.php:30-33`
- Evidence (probe): self-follow checks `view`, not `follow`; watcher status grants view after the grant is revoked.
- Fix: require the `follow` policy; don't let watcher status outlive the basis that granted access.

### RV-SEC-10 — Teams/Slack one-click approval bypasses the second-factor requirement and isn't bound to the clicker
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-sec-chat-approve`): cards drop one-click Approve and the action refuses tokens while the second-factor setting is on; Slack clicker must match the approver's `slack_user_id`. **Residual (S3, open):** Teams requests are not Bot Framework JWT-verified (the HMAC token is the bearer credential), and `notification_channels.manage` holders could remap other users' chat identities (**fixed in `feature/rv-s3-chat-identity`**: `manageIdentity` rule + activity log). Teams JWT verification remains open · Location: `ApproveSubmissionFromChatAction.php:32`, `TeamsActionsController`, `SlackInteractionsController`, `settings/notification-channels.blade.php:80-90`
- Evidence (probe): with “require second factor” on, a chat token completes the workflow. Teams POSTs aren't JWT-verified; Slack clicker isn't matched to the approver; channel managers can remap another user's chat identity.
- Fix: suppress chat approve (send the confirmation URL) when a second factor is required; verify Teams JWT and Slack user; single-use tokens; owner/re-auth for identity mapping.

### RV-SEC-11 — Custom outcome key injected into a Livewire action expression
- Severity: **S2** · Status: **Fix in review** (branch `feature/rv-sec-builder`; keys validated on create/update, unsafe stored keys ignored on read, rendered with `Js::from`) · Location: `submissions/show.blade.php:957`; `workflow-builder.blade.php:485-496`
- Evidence (probe): an admin saves outcome key ``x');alert(document.domain);('``; it renders inside `wire:click="act('…')"` and executes for approvers.
- Fix: validate keys `^[a-z0-9_]{1,40}$` server-side; render with `@js()`.

### Further Wave 1 findings (S3/S4)

| ID | Sev | Finding | Location |
|---|---|---|---|
| RV-ENG-07 | S3 | **Fix in review** (`feature/rv-s3-integrity`: `AppendOnlyBuilder` refuses bulk update/delete/upsert/increment; FK actions closed by RV-ENG-06; raw `DB::table()` writes and DB triggers out of scope — triggers need privileges shared hosts often lack) — Append-only guard is model-event only; builder/DB updates and FK actions bypass it | `app/Models/WorkflowAction.php:52-60` |
| RV-DATA-04 | S3 | **Fix in review** (`feature/rv-s3-integrity`: whole dump in one read transaction) — Database backups aren't a consistent snapshot (per-table cursors, no transaction) | `CreateDatabaseBackupAction.php:69-77` |
| RV-BUG-03 | S3 | **Fix in review** (`feature/rv-s3-local-time`; also out-of-office and auditor-access forms) — Delegation window parsed in `APP_TIMEZONE`, not the user's | `submissions/show.blade.php:259-284` |
| RV-SEC-09 | S3 | **Fix in review** (`feature/rv-s3-authz`) —  Mentions add watchers without `addWatcher`; mention list not locked | `PostCommentAction.php:58`, `FollowSubmissionAction.php:35` |
| RV-SEC-12 | S3 | **Fix in review** (`feature/rv-s3-authz`) —  API submitters can choose any department, steering department-manager routing | `StoreSubmissionRequest.php:27` |
| RV-SEC-13 | S3 | **Fix in review** (`feature/rv-s3-permission-keys`; decided 2026-10-16: enforce + backfill. `submissions.view.own` intentionally does not gate a requester's own request — enforcing it locked people out of answering information requests and returns) — Permission catalogue keys with no enforcement (`submissions.withdraw`, `comments.create`, `reminders.send`, `submissions.view.own`, `audit.evidence.export`, `audit.auditor_access.manage`) | `app/Policies/SubmissionPolicy.php` and others |
| RV-SEC-14 | S3 | **Fix in review** (`feature/rv-s3-mail-branding`; mail only — Slack/Teams text not changed) —  Markdown link injection in notification emails via title/comment | `RendersNotificationCopy::mailFromCopy` |
| RV-SEC-15 | S3 | **Deferred to Wave 3** (needs an ADR: moving to Alpine's CSP build means replacing inline `x-data`/expression usage across pages and Flux; not a patch-release change) — CSP allows `unsafe-inline` and `unsafe-eval`, so it doesn't mitigate XSS | `app/Http/Middleware/SecurityHeaders.php:34` |
| RV-SEC-16 | S3 | **Fix in review** (`feature/rv-s3-mail-branding`: password confirmation age enforced; second-factor recency still session-level, needs an in-page re-verify step) —  Custom-code save accepts a password confirmation of any age; second factor is login-time only | `settings/branding.blade.php:208-209` |
| RV-SEC-17 | S3 | **Fix in review** (`feature/rv-s3-chat-identity`: CURLOPT_RESOLVE pins via the SDK http options; virtual-host name validated) — S3 backup endpoint validated but not pinned; virtual-host bucket name never validated (suspected) | `S3BackupTransport.php:71-77` |
| RV-SEC-19 | S3 | **Fix in review** (`feature/rv-s3-authz`; four-eyes follows the separation-of-duties setting) —  Disposition review has no four-eyes or still-pending check | `ReviewDispositionAction` |
| RV-ENG-08 | S4 | **Fix in review** (`feature/rv-sec-builder`) — Unknown `act()` argument is treated as Approve | `submissions/show.blade.php:313` |
| RV-ENG-09 | S4 | Escalate timeout on the last step fails and is logged every 15 min forever | `WorkflowEngine.php:428-430` |
| RV-BUG-04 | S4 | Double submit of a draft returns a 500 (unique constraint) | `SubmitSubmissionAction` |
| RV-SEC-18 | S4 | Minor: optional Telegram webhook secret; CSV formula escaping in two exports; unsanitized download filename header; VAPID key in plaintext; inline `svg` mapping; CSV import row cap; Sanctum tokens never expire; role-name checks in dashboard/auditor; hidden owner name via tampered mentions | various |
| RV-FEAT-01 | — | Follow-up feature: administrator "reassign step" for requests whose only approver is excluded (engine-recorded, audited, with Help) | — |
| RV-DOC-01 | S4 | `docs/openapi-v1.yaml` is a 73-line stub that omits most endpoints (including `POST /submissions/{id}/actions`); `docs/API.md` is the only contract | `docs/openapi-v1.yaml` |
| RV-TEST-05 | S2 | **Fix in review** — `InstallerTest` leaked the RefreshDatabase transaction on non-`mysql` connections, hanging the MariaDB job | `tests/Feature/InstallerTest.php` |
| RV-ENG-10 | ? | Suspected: Escalate advances a group step on one assignee's action | `WorkflowEngine.php:421-433` |
| RV-ENG-11 | ? | Suspected: “All” step can stall when its assignee set shrinks after others approved | `isStepSatisfied` |

Checked and found correct in Wave 1 (summary): engine transactions/locks/duplicate checks; all 37 `lockForUpdate()` calls outside the engine run in transactions with id-ordered multi-row locks; timeout/escalation idempotency; signed file/report/backup downloads with per-user authorization; API scopes on every endpoint; global search filtering; theme CSS validation; Help Markdown; XLSX import guard; attachment serving; `OutboundDestination` SSRF guard with DNS pinning for webhooks/connectors/web push; Slack request signatures; secrets encrypted at rest and never re-sent.

---

## Leads awaiting verification (from REVIEW_PLAN §4)

| Lead | Track | Wave | Status |
|---|---|---|---|
| L1 `json_extract` comparison in `AssignedStepConstraint` may never match on MariaDB | BUG/DATA | 1 | Confirmed → RV-BUG-02 |
| L2 Oversized Livewire page components | ARC | 3 | Open |
| L3 Authorization only at route level in 26 pages; 12 `#[Locked]` properties | SEC | 1 | Reviewed → RV-SEC-06, RV-SEC-07, RV-SEC-11; other pages authorize per action |
| L4 13 raw `{!! !!}` outputs | SEC | 1 | Reviewed → RV-SEC-03 (fixed); theme CSS, Help, QR found safe |
| L5 `hasRole` outside Policies (7 files) | ARC/SEC | 1 | Reviewed → RV-SEC-04, RV-SEC-18 |
| L6 Per-key `rememberForever` settings on file cache | PERF | 2 | **Fix in review**: one `settings.all` entry, memoised per request; also fixed caching of the first caller's default for missing keys |
| L7 40 `lockForUpdate()` calls outside the engine | ENG | 1 | Reviewed: all transactional; inversions → RV-ENG-05 |
| L8 Engine coverage target | TEST | — | → RV-TEST-04 |
| L9 Missing ADRs | DOC | 3 | Open |
| L10 Docker files vs shared-hosting constraint | ARC | 3 | Open |
