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
- Severity: **S2** · Status: **Fixed, merged #25** (branch `feature/rv-test-browser-ci`; CI wiring fixed, `continue-on-error` removed; `browser` job green on #68). Flaky draft-flow waits remain open.
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
- Severity: S3 · Status: **Fixed, merged #47** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/dashboard.blade.php` · Evidence: 43 → 70 queries for 3 → 30 submissions.

### RV-PERF-02 — Approvals inbox issues ~1 query per pending item
- Severity: S3 · Status: **Fixed, merged #47** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/approvals/inbox.blade.php` · Evidence: 21 → 48 queries.

### RV-PERF-03 — Submission detail issues ~2 queries per comment; lazy-loads `Comment::commentable`
- Severity: S3 · Status: **Fixed, merged #47** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/submissions/show.blade.php` · Evidence: 52 → 106 queries for 3 → 30 comments; lazy-load violation `Comment::commentable`.

### RV-PERF-04 — Analytics query count grows with submissions
- Severity: S3 · Status: **Fixed, merged #47** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/admin/analytics.blade.php`, `app/Services/AnalyticsService.php` · Evidence: 23 → 50 queries.

### RV-PERF-05 — Notifications centre issues ~2.4 queries per notification
- Severity: S3 · Status: **Fixed, merged #47** (branch `feature/rv-perf-n-plus-one`; guarded by `QueryScalingTest`) · Location: `resources/views/pages/notifications/index.blade.php` · Evidence: 37 → 103 queries.

### RV-TEST-03 — Query budget tests cannot detect row-level N+1
- Severity: S3 · Status: **Fixed (on `main`)** (`QueryScalingTest`: 9 pages, equal query counts at two dataset sizes) · Location: `tests/Feature/QueryBudgetTest.php`
- Evidence: fixed budgets measured at 5–20 rows; none of RV-PERF-01…05 trips them.
- Fix: Wave 2 — assert equal query counts at two dataset sizes per page, and cover the five pages above.

### RV-LAR-01 — No lazy-loading / strict-model guard outside production
- Severity: S3 · Status: **Fixed (on `main`)** (`Model::preventLazyLoading(! app()->isProduction())`; the three violations the suite surfaced are fixed) · Location: `app/Providers/AppServiceProvider.php`
- Fix: `Model::shouldBeStrict(! app()->isProduction())` (or at least `preventLazyLoading`) after RV-PERF-03 is fixed.

### RV-TEST-04 — `WorkflowEngine` coverage is only just above its gate
- Severity: S3 · Status: Open — **decision pending** (REVIEW_PLAN §11 Q4) · Evidence: 97.10% vs a 97% gate; AGENTS.md states 100%.

### RV-DATA-01 — MariaDB 10.6 is supported but untested
- Severity: S2 · Status: **Fixed** (CI job added in PR #23) · Location: `.github/workflows/ci.yml` (`mariadb` job)
- First run: 882 of 949 tests fail, all from one cause → RV-DATA-02.
- Second blocker (RV-TEST-05): after RV-DATA-02 the job stalled in `InstallerTest` for 30+ minutes. A CI watchdog showed connection A idle inside an open transaction and connection B's `drop table …` (migrate:fresh) "Waiting for table metadata lock". Cause: `InstallerTest::restoreTestingDatabase()` forced `database.default=sqlite` and purged only `mysql` before `RefreshDatabase` rolled back, so the rollback went to sqlite and the `mariadb` transaction leaked (on MySQL the purge happened to close it). Fixed on branch `feature/rv-test-mariadb-installer` by restoring and keeping the recorded testing connection.

### RV-DATA-02 — Migrations fail or silently corrupt timestamps on MariaDB 10.6–10.9
- Severity: **S1** · Status: **Fixed, merged #23** (PR #23, commit `d3b9803`)
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
- Severity: **S1** · Status: **Fixed, merged #27** (branch `feature/rv-eng-separation-of-duties`) — decided 2026-10-16: setting "Requesters cannot approve their own requests", **on by default**. The requester is removed from every resolved assignee set, self-delegation is refused, and the engine rejects any state-changing action by the requester. If the requester would be a step's **only** approver, routing stops with a message naming the step (decided 2026-10-16; an administrator "reassign step" tool is a separate follow-up, RV-FEAT-01).
- Evidence (probes): submitter listed on a User/Role step; department manager submitting for their own department; FormField step where the submitter picks themselves; delegation to the submitter. `WorkflowEngine::authorize()` (`app/Services/WorkflowEngine.php:239-268`) and `AssigneeResolver` never compare the actor with `submitter_id`.

### RV-DATA-03 — MySQL backup promote drops live tables before a non-atomic reload, with no pre-promote copy
- Severity: **S1** (data loss; owner-initiated) · Status: **Fixed, merged #40** (branch `feature/rv-data-promote-safety`). Shared hosting usually cannot create a scratch schema, so instead of `RENAME TABLE` the promote: enters maintenance mode → takes and verifies a safety backup → loads → verifies → reopens; any failure reloads the safety backup, and a failed rollback keeps maintenance mode on with a critical log naming the safety snapshot. Restore trail records the safety snapshot and discarded workflow actions; the confirmation warns of the loss beforehand
- Location: `app/Actions/Backups/PromoteDatabaseBackupAction.php:182-200`
- Evidence: `SET FOREIGN_KEY_CHECKS=0`, drop every table, `DB::unprepared($sql)`, then bring the app up and verify. A failure mid-load leaves a partial database; the SQLite path keeps a `.pre-promote` copy, the MySQL path does not. A successful promote silently discards newer `workflow_actions`, and the restore trail doesn't record how many.
- Fix: snapshot before dropping; load into a scratch schema and swap with `RENAME TABLE`; stay in maintenance until verified; record the live-vs-snapshot `workflow_actions` delta.

### RV-ENG-02 — Replayed or double-submitted actions approve the *next* step
- Severity: **S2** · Status: **Fixed, merged #29** (branch `feature/rv-eng-stale-step`; also closes RV-ENG-02's batch variant). `expectedStepId` is optional on the engine for backward compatibility; every production caller passes it. API clients opt in with `step_id`
- Evidence (probe): `processAction()` takes no expected step and acts on whatever `current_step_id` is after the lock. Pre-lock checks in `EmailApprovalController:60` and `ApproveSubmissionFromChatAction:190` race; API, Livewire `act()` and `BatchApproveSubmissionsAction:112-133` don't check at all. A user on steps 1 and 2 completes both with one double-click/retry.
- Fix: required `expectedStepId` on `processAction()`/`delegate()`, checked after the lock; pass it from every caller.

### RV-ENG-03 — One quorum/“All” seat counted twice (delegate + delegator)
- Severity: **S2** · Status: **Fixed, merged #32** (branch `feature/rv-eng-delegated-seat`). New migration adds nullable `workflow_actions.on_behalf_of_id` (restrict on delete); the engine counts distinct seats and treats a delegate's decision as the delegator's. Pre-existing delegate decisions have no seat recorded and keep the old counting · Location: `WorkflowEngine.php:481-489, 673-686`
- Evidence (probe): quorum 2 of {A,B,C}; A delegates to D, D approves; after expiry A approves → step complete without B or C.
- Fix: count approvals by seat (record on-behalf-of); treat “my delegate already decided” as a duplicate.

### RV-ENG-04 — Approvals survive return-for-revision
- Severity: **S2** · Status: **Fixed, merged #31** (branch `feature/rv-eng-review-round`; decided 2026-10-16: a return resets the step). `App\Services\Workflow\ReviewRound` counts only actions after the latest return on that step, in the engine, workbench, dashboard query, and request page. This reverses a previously tested behaviour (`ParallelWorkflowTest` updated) · Location: `WorkflowEngine::resume()` (`:692-723`), `isStepSatisfied`
- Evidence (probe): parallel {A,B}; A approves, B returns, submitter revises; B approves → complete. A never saw the revised content and is blocked as a duplicate.
- Fix: append a `resubmitted` system action; count approvals/duplicates only after the latest return on that step.

### RV-ENG-05 — Withdraw mutates the workflow instance outside the engine
- Severity: **S2** · Status: **Fixed, merged #33** (branch `feature/rv-eng-withdraw`; `WorkflowEngine::withdraw()` locks the instance first, re-checks it is open, appends a `withdraw` action, then cancels instance and submission) · Location: `app/Actions/Submissions/WithdrawSubmissionAction.php:57-64`
- Evidence: updates `workflow_instances` without a lock or an action row (breaks invariant 2); can overwrite a just-committed completion; locks submission→instance while the engine locks instance→submission (deadlock risk on MySQL).
- Fix: `WorkflowEngine::withdraw()` with lock, status re-check, appended `withdraw` action.

### RV-ENG-06 — Deleting a user rewrites `workflow_actions.delegated_to` via FK cascade
- Severity: **S2** (audit tampering) · Status: **Fixed, merged #34** (branch `feature/rv-eng-audit-fk`; new migration makes `delegated_to` and `instance_id` restrict-on-delete; evidence check covers delegations, on-behalf-of, and comments; prune skips users with evidence)
- Evidence (probe): `delegated_to … nullOnDelete()`; `hasPreservedAuditEvidence()` ignores `delegated_to`; `users:prune-unconfirmed` bulk-deletes with no evidence check. `instance_id cascadeOnDelete` is latent risk.
- Fix: new migration → `restrictOnDelete`; extend the evidence check; make prune skip users with evidence.

### RV-BUG-01 — Manager / department / form-field approvers see no action buttons on the request page
- Severity: **S2** · Status: **Fixed, merged #27** (with RV-ENG-01; regression test `test_dynamic_approvers_can_act_from_the_request_page`) · Location: `resources/views/pages/submissions/show.blade.php:171, 222, 239`
- Evidence (probe): `$step->assignedUsers()` is called without the submission, so dynamic assignees resolve to nobody and `canAct` is false.
- Fix: pass `$instance->submission`.

### RV-BUG-02 — Form-field approvers missing from dashboard and API lists on MariaDB (lead L1)
- Severity: **S2** · Status: **Fixed, merged #35** (branch `feature/rv-bug-json-assignee`; the field key is selected through the query grammar's JSON path, unquoted per driver; new test exercises the SQL path so the MariaDB job guards it). `External` assignees still have no SQL branch (their resolvers are PHP-only) — tracked as a known limitation · Location: `app/Services/Workflow/AssignedStepConstraint.php:71`
- Evidence: MariaDB `JSON_EXTRACT` returns the quoted string, so `key = '"reviewer"'` never matches; SQLite/MySQL 8 match. No test covers this branch. The constraint also has no branch for `External` assignees on any database.
- Fix: driver-aware unquote (or Laravel's `->` JSON path); add a feature test so the MariaDB job guards it.

### RV-SEC-04 — Admins can take over or wipe higher-privileged accounts (email change, anonymize)
- Severity: **S2** · Status: **Fixed, merged #30** (branch `feature/rv-sec-user-ceiling`). New `UserPolicy::manageIdentity` (non-owner: target is not admin/super-admin and within the actor's permission ceiling) guards email changes, activation, and anonymization; ordinary edits keep the existing "Roles you cannot remove" design. Not done: notify the old address on email change (hardening backlog) · Location: `app/Policies/UserPolicy.php:19-42`, `app/Actions/Users/UpdateUserAction.php:27,66`
- Evidence (probes): a non-owner admin (or custom role with `users.update`) changes the email of a user holding above-ceiling permissions, then resets the password; admin A anonymizes peer admin B (edit is refused, anonymize is not).
- Fix: enforce the admin/permission-ceiling rule in `UserPolicy::update` (inherited by anonymize and name decisions); notify the old address and re-verify on email change.

### RV-SEC-06 — User import "update" mode rewrites any user without `users.update`
- Severity: **S2** · Status: **Fixed, merged #37** (branch `feature/rv-sec-import-update`; per-row `update` policy plus admin-only-by-owner rule; `$preview` is `#[Locked]`) · Location: `app/Services/Users/UserImportService.php:233-243`; `pages/admin/users-import.blade.php` (`$preview` not locked)
- Evidence (probes): an importer sets a victim's `manager_id` to themselves and becomes approver of the victim's in-flight requester-manager steps.
- Fix: authorize `update` per row (with RV-SEC-04's rule); lock `$preview` or re-analyse on commit.

### RV-SEC-07 — Workflow builder publishes without `workflows.publish`
- Severity: **S2** · Status: **Fixed, merged #28** (branch `feature/rv-sec-builder`). Residual, by design for now: saving a template that has never been used still auto-publishes for `workflows.manage` holders (`UpdateWorkflowTemplateAction`); revisit with RV-SEC-13 · Location: `pages/admin/workflow-builder.blade.php:276-282`
- Evidence (probe): `confirmPublish()` authorizes only `update`.
- Fix: `authorize('publish')` in `requestPublish`/`confirmPublish` and inside `PublishWorkflowTemplateVersionAction`.

### RV-SEC-08 — Time-limited auditor or delegate access becomes permanent by following
- Severity: **S2** · Status: **Fixed, merged #38** (branch `feature/rv-sec-follow-access`; self-follow requires the `follow` policy; only followers added by someone else grant view) · Location: `app/Actions/Collaboration/FollowSubmissionAction.php:30-33`
- Evidence (probe): self-follow checks `view`, not `follow`; watcher status grants view after the grant is revoked.
- Fix: require the `follow` policy; don't let watcher status outlive the basis that granted access.

### RV-SEC-10 — Teams/Slack one-click approval bypasses the second-factor requirement and isn't bound to the clicker
- Severity: **S2** · Status: **Fixed, merged #39** (branch `feature/rv-sec-chat-approve`): cards drop one-click Approve and the action refuses tokens while the second-factor setting is on; Slack clicker must match the approver's `slack_user_id`. **Residual (S3, open):** Teams requests are not Bot Framework JWT-verified (the HMAC token is the bearer credential), and `notification_channels.manage` holders could remap other users' chat identities (**fixed in `feature/rv-s3-chat-identity`**: `manageIdentity` rule + activity log). Teams JWT verification remains open · Location: `ApproveSubmissionFromChatAction.php:32`, `TeamsActionsController`, `SlackInteractionsController`, `settings/notification-channels.blade.php:80-90`
- Evidence (probe): with “require second factor” on, a chat token completes the workflow. Teams POSTs aren't JWT-verified; Slack clicker isn't matched to the approver; channel managers can remap another user's chat identity.
- Fix: suppress chat approve (send the confirmation URL) when a second factor is required; verify Teams JWT and Slack user; single-use tokens; owner/re-auth for identity mapping.

### RV-SEC-11 — Custom outcome key injected into a Livewire action expression
- Severity: **S2** · Status: **Fixed, merged #28** (branch `feature/rv-sec-builder`; keys validated on create/update, unsafe stored keys ignored on read, rendered with `Js::from`) · Location: `submissions/show.blade.php:957`; `workflow-builder.blade.php:485-496`
- Evidence (probe): an admin saves outcome key ``x');alert(document.domain);('``; it renders inside `wire:click="act('…')"` and executes for approvers.
- Fix: validate keys `^[a-z0-9_]{1,40}$` server-side; render with `@js()`.

### RV-PERF-06 — Hot-path queries without supporting indexes
- Severity: S3 · Status: **Fixed, merged #48** (branch `feature/rv-perf-indexes`)
- Evidence: step completion and review-round checks filter `workflow_actions` by (instance_id, step_id, action) — only (instance_id, actor_id) existed; the unread-notification badge (every page) filters `notifications` by read_at; analytics/lists filter `submissions` by created_at.
- Fix: additive migration with three named indexes. SQLite query plans confirm use (covering index for the unread count). MySQL/MariaDB `EXPLAIN` on the performance dataset is still to do (no local MySQL; CI blocked by billing).

### Further Wave 1 findings (S3/S4)

| ID | Sev | Finding | Location |
|---|---|---|---|
| RV-ENG-07 | S3 | **Fixed, merged #41** (`feature/rv-s3-integrity`: `AppendOnlyBuilder` refuses bulk update/delete/upsert/increment; FK actions closed by RV-ENG-06; raw `DB::table()` writes and DB triggers out of scope — triggers need privileges shared hosts often lack) — Append-only guard is model-event only; builder/DB updates and FK actions bypass it | `app/Models/WorkflowAction.php:52-60` |
| RV-DATA-04 | S3 | **Fixed, merged #41** (`feature/rv-s3-integrity`: whole dump in one read transaction) — Database backups aren't a consistent snapshot (per-table cursors, no transaction) | `CreateDatabaseBackupAction.php:69-77` |
| RV-BUG-03 | S3 | **Fixed, merged #45** (`feature/rv-s3-local-time`; also out-of-office and auditor-access forms) — Delegation window parsed in `APP_TIMEZONE`, not the user's | `submissions/show.blade.php:259-284` |
| RV-SEC-09 | S3 | **Fixed, merged #42** (`feature/rv-s3-authz`) —  Mentions add watchers without `addWatcher`; mention list not locked | `PostCommentAction.php:58`, `FollowSubmissionAction.php:35` |
| RV-SEC-12 | S3 | **Fixed, merged #42** (`feature/rv-s3-authz`) —  API submitters can choose any department, steering department-manager routing | `StoreSubmissionRequest.php:27` |
| RV-SEC-13 | S3 | **Fixed, merged #43** (`feature/rv-s3-permission-keys`; decided 2026-10-16: enforce + backfill. `submissions.view.own` intentionally does not gate a requester's own request — enforcing it locked people out of answering information requests and returns) — Permission catalogue keys with no enforcement (`submissions.withdraw`, `comments.create`, `reminders.send`, `submissions.view.own`, `audit.evidence.export`, `audit.auditor_access.manage`) | `app/Policies/SubmissionPolicy.php` and others |
| RV-SEC-14 | S3 | **Fixed, merged #44** (`feature/rv-s3-mail-branding`; mail only — Slack/Teams text not changed) —  Markdown link injection in notification emails via title/comment | `RendersNotificationCopy::mailFromCopy` |
| RV-SEC-15 | S3 | **ADR 0005 step 1 merged (#76); step 2 fix in review** (`feature/rv-sec-csp-report-only`: `/csp-report` endpoint and `csp` log, report-only strict policy behind `LIVEWIRE_CSP_SAFE`, 12 Alpine expressions moved to `Alpine.data`, owner origin allow-list, `AlpineCspSafeTemplatesTest`, non-required `browser-csp-safe` CI job). Step 3 (enforce, after cache-busting the Livewire script) open; was deferred (needs an ADR: moving to Alpine's CSP build means replacing inline `x-data`/expression usage across pages and Flux; not a patch-release change) — CSP allows `unsafe-inline` and `unsafe-eval`, so it doesn't mitigate XSS | `app/Http/Middleware/SecurityHeaders.php:34` |
| RV-SEC-16 | S3 | **Fixed, merged #44** (`feature/rv-s3-mail-branding`: password confirmation age enforced; second-factor recency still session-level, needs an in-page re-verify step) —  Custom-code save accepts a password confirmation of any age; second factor is login-time only | `settings/branding.blade.php:208-209` |
| RV-SEC-17 | S3 | **Fixed, merged #46** (`feature/rv-s3-chat-identity`: CURLOPT_RESOLVE pins via the SDK http options; virtual-host name validated) — S3 backup endpoint validated but not pinned; virtual-host bucket name never validated (suspected) | `S3BackupTransport.php:71-77` |
| RV-SEC-19 | S3 | **Fixed, merged #42** (`feature/rv-s3-authz`; four-eyes follows the separation-of-duties setting) —  Disposition review has no four-eyes or still-pending check | `ReviewDispositionAction` |
| RV-ENG-08 | S4 | **Fixed, merged #28** (`feature/rv-sec-builder`) — Unknown `act()` argument is treated as Approve | `submissions/show.blade.php:313` |
| RV-ENG-09 | S4 | Escalate timeout on the last step fails and is logged every 15 min forever | `WorkflowEngine.php:428-430` |
| RV-BUG-04 | S4 | Double submit of a draft returns a 500 (unique constraint) | `SubmitSubmissionAction` |
| RV-SEC-18 | S4 | Minor: optional Telegram webhook secret; CSV formula escaping in two exports; unsanitized download filename header; VAPID key in plaintext; inline `svg` mapping; CSV import row cap; Sanctum tokens never expire; role-name checks in dashboard/auditor; hidden owner name via tampered mentions | various |
| RV-FEAT-01 | — | Follow-up feature: administrator "reassign step" for requests whose only approver is excluded (engine-recorded, audited, with Help) | — |
| RV-DOC-01 | S4 | `docs/openapi-v1.yaml` is a 73-line stub that omits most endpoints (including `POST /submissions/{id}/actions`); `docs/API.md` is the only contract | `docs/openapi-v1.yaml` |
| RV-TEST-05 | S2 | **Fixed, merged #36** — `InstallerTest` leaked the RefreshDatabase transaction on non-`mysql` connections, hanging the MariaDB job | `tests/Feature/InstallerTest.php` |
| RV-ENG-10 | ? | Suspected: Escalate advances a group step on one assignee's action | `WorkflowEngine.php:421-433` |
| RV-ENG-11 | ? | Suspected: “All” step can stall when its assignee set shrinks after others approved | `isStepSatisfied` |

Checked and found correct in Wave 1 (summary): engine transactions/locks/duplicate checks; all 37 `lockForUpdate()` calls outside the engine run in transactions with id-ordered multi-row locks; timeout/escalation idempotency; signed file/report/backup downloads with per-user authorization; API scopes on every endpoint; global search filtering; theme CSS validation; Help Markdown; XLSX import guard; attachment serving; `OutboundDestination` SSRF guard with DNS pinning for webhooks/connectors/web push; Slack request signatures; secrets encrypted at rest and never re-sent.

## Wave 2 correctness findings (2026-10-16)

Sources: two read-only feature-area reviews with 43 probe tests (every listed CONFIRMED item reproduced by a probe that passes on the buggy code; probes re-run before logging).

| ID | Sev | Finding | Status |
|---|---|---|---|
| RV-SEC-20 | **S1** | Microsoft sign-in links an existing account (admins included) by unverified `email`/`preferred_username` and skips that account's second factor (`OAuthClient.php:186`, `ResolveOAuthUserAction.php:33`, `OAuthController.php:44`) | **Fixed, merged #49** (`feature/rv-sec-oauth-verified`) |
| RV-BUG-05 | **S1** | Saving on **Workflow templates** resets every step's completion rule to its step-type default ("All" → first response) and drops custom outcomes, auto-publishing on unused templates (`workflow-templates.blade.php:350, 358-366`) | **Fixed, merged #50** (`feature/rv-bug-templates-page-save`) |
| RV-BUG-06 | S2 | Changing your email on Profile clears `email_verified_at`; `users:prune-unconfirmed` then deletes the (old) account | **Fixed, merged #51** (`feature/rv-bug-prune-registrations`) |
| RV-DATA-05 | S2 | Daily `PurgeExpiredDelegations` deletes delegation rows, so evidence packs lose who the delegate was; `workflow.json` omits `delegated_to`/window | **Fixed, merged #51** (`feature/rv-bug-prune-registrations`) |
| RV-BUG-07 | S2 | Submit failures (e.g. no resolvable approver): raw 500 in UI; custom-form retries leave an orphan draft per attempt; API create+submit leaves a stranded Pending request with no workflow | **Fixed, merged #53** (`feature/rv-bug-submit-failures`) |
| RV-ENG-12 | S2 | Delegations can't be revoked: a deactivated delegate, or a deleted/shortened out-of-office period, leaves the delegator locked out until expiry | **Fixed, merged #51** (`feature/rv-bug-prune-registrations`) |
| RV-BUG-08 | S2 | Condition branch targets are positions; moving/deleting/duplicating steps in builder or templates page re-points branches silently | **Fixed, merged #55** (`feature/rv-bug-conditional-routing`) |
| RV-BUG-09 | S2 | Builder resave turns `in`/`not_in` lists into a string that never matches | **Fixed, merged #55** (`feature/rv-bug-conditional-routing`) |
| RV-BUG-10 | S2 | Builder publishes conditions on unknown fields; every submission on that template then fails | **Fixed, merged #55** (`feature/rv-bug-conditional-routing`) |
| RV-BUG-11 | S2 | `metadata.*` selection rules/conditions never match real requests (nothing writes metadata), but Explain routing and simulation say they do | **Fixed, merged #55** (`feature/rv-bug-conditional-routing`) |
| RV-DATA-06 | S2 | On a returned/awaiting-information request the submitter can hard-delete an attachment approvers already saw; no "removed" revision, ignores legal hold | **Fixed, merged #56** (`feature/rv-data-attachments-references`) |
| RV-BUG-12 | S2 | With two file fields, each field is linked to the other's uploads (`create-custom.blade.php:115`) | **Fixed, merged #53** (`feature/rv-bug-submit-failures`) |
| RV-BUG-13 | S2 | Visibility/required rule on a multi-select source crashes the form ("Array to string conversion") | **Fixed, merged #55** (`feature/rv-bug-conditional-routing`) |
| RV-BUG-14 | S2 | Returning to sequential numbering after random can collide and then block every new request (counter rollback) | **Fixed, merged #56** (`feature/rv-data-attachments-references`) |
| RV-BUG-15 | S3 | Chat send failures recorded as Sent (never retried/shown); unmapped WhatsApp kinds recorded Sent, not Skipped | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-16 | S3 | Quiet-hours-deferred chat messages lose their copy and signed link; sent even if the step moved on | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-17 | S3 | Decision emails show literal `{{comment}}` or a stale return comment on an Approved email | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-18 | S3 | Daily digests skip a day when the job starts seconds earlier than yesterday | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-19 | S3 | Reminder cap counts recipients, so steps with more approvers than the cap can never be reminded; one recent recipient aborts the batch | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-20 | S3 | Deactivated/anonymized people still receive decision, comment, and digest email | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-21 | S3 | API lets a requester resume an information request without any reply | **Fixed, merged #53** (`feature/rv-bug-submit-failures`) |
| RV-SEC-21 | S3 | Evidence-pack verifier accepts extra files injected into the zip; manifest metadata unsigned | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-DATA-07 | S3 | Evidence packs omit comment/submission/file revision histories and most notifications; notification query loads the whole table | **Fixed, merged #60** (`feature/rv-wave2-s3`; email notifications are not recorded per request (would need a NotificationSent listener)) |
| RV-BUG-22 | S3 | Approval report shows a withdrawn step as Completed and lists today's assignees instead of who acted | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-23 | S3 | Files-backup promote doesn't restore branding artwork (left in restore-holding) | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-SEC-22 | S3 | Anonymizing keeps the profile photo (still served to admins), watchers, and future delegations | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-24 | S3 | Import "update existing" skips row validation (phone, department, manager) | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-25 | S3 | Import silently drops a manager whose own row failed | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-DATA-08 | S3 | Retention only soft-deletes; nothing is ever purged though Help says "removed" | **Fixed, merged #60** (`feature/rv-wave2-s3`; decided: keep evidence — Help corrected to "retired, kept as evidence"; no purge built because hard-deleting would cascade into append-only history) |
| RV-BUG-26 | S3 | Dispositions allowed before a request is finished; approve/release errors surface as 500 | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-27 | S3? | Concurrent requests with the same Idempotency-Key both run (suspected race) | **Fixed, merged #60** (`feature/rv-wave2-s3`; reproduced) |
| RV-BUG-28 | S3 | Request-page timeline shows the latest published version, not the instance's pinned version | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-29 | S3 | Hidden parent's stale value still makes descendants required, then the answer is discarded | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-30 | S3 | Calculated total on the review screen differs from the stored total | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-31 | S3 | Saving a request-type draft re-activates an archived type | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-32 | S3 | Editing a General Request draft/returned request clears its department | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-33 | S3 | API submit / direct `resubmit()` skips required custom fields | **Fixed, merged #53** (`feature/rv-bug-submit-failures`) |
| RV-BUG-34 | S3 | 24-hour business-hours mode counts the hour before a working day (deadlines up to 1h early) | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-35 | S3 | Date-time field max date rejects that whole day | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-36 | S3 | Required checkbox passes when unticked | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-DOC-02 | S3 | Help says manager/form-field assignees are fixed at submit; they resolve live; `resume()` lacks the empty-assignee check | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-BUG-37 | S3? | Stale builder save can publish over another admin's version (no version check; suspected) | **Fixed, merged #60** (`feature/rv-wave2-s3`; reproduced) |
| RV-BUG-38 | S4 | Minor: mentions added by editing a comment are ignored; API docs field name/list scope/retry count; draft retention counts from creation; files-promote leftovers; builder preview parity; reference period read twice at boundaries | Open |
| RV-DATA-09 | S3 | Email notifications are not recorded per request (only chat channels write `notification_deliveries`), so evidence packs cannot list emails sent; needs a `NotificationSent` listener for the mail channel (found while fixing RV-DATA-07) | Open |

## Wave 2 shared-hosting performance findings (2026-10-16)

Measured on the Wave 0 performance dataset (2,857 submissions, 2,008 active workflows) with APP_ENV=production and file cache; SQLite, single core.

| ID | Sev | Finding | Measured | Status |
|---|---|---|---|---|
| RV-PERF-07 | S2 | Global search (header, every page, live) scans all matching submissions and runs the view policy per row in PHP (`SubmissionSearchService.php:55-58`) | submitter typing "re": 11.9 s, 14,621 queries | **Fixed, merged #58** (`feature/rv-perf-pages`) |
| RV-PERF-08 | S2 | Approvals inbox loads every active workflow and renders all items unpaginated; recomputed on every checkbox | busy approver: 4.8 s, 94 MB, 5.6 MB HTML | **Fixed, merged #58** (`feature/rv-perf-pages`) |
| RV-PERF-09 | S2 | Request page runs the whole workbench on every render just for Previous/Next | busy approver: 4.4 s, 70 MB | **Fixed, merged #58** (`feature/rv-perf-pages`) |
| RV-PERF-10 | S2 | Admin dashboard and analytics hydrate the whole 30-day cohort in PHP, uncached | 3.4 s, 60 MB | **Fixed, merged #58** (`feature/rv-perf-pages`) |
| RV-PERF-11 | S2 | Out-of-office sweep is O(away users × active workflows × ~5 queries) every 15 min | 5 away × 2,008: 27.9 s, 50,247 queries | **Fixed, merged #57** (`feature/rv-ops-scheduler-jobs`) |
| RV-OPS-01 | S2 | Scheduled `queue:work` has no `--max-time`, foreground, 24 h overlap lock (stale after a kill → no mail for a day); `retry_after` = `--timeout` | traced | **Fixed, merged #57** (`feature/rv-ops-scheduler-jobs`) |
| RV-OPS-02 | S2 | `withoutOverlapping()` on `Schedule::job()` only guards the dispatch; copies pile up when the worker lags | traced | **Fixed, merged #57** (`feature/rv-ops-scheduler-jobs`) |
| RV-PERF-12 | S2 | Evidence pack loads the whole `notification_deliveries` table (no submission_id column); attachments read fully into memory to hash | 100k deliveries: 9.0 s, 273 MB | **Fixed, merged #57** (`feature/rv-ops-scheduler-jobs`) |
| RV-OPS-03 | S2 | Backups/promote/verify read whole files into memory and run inside the web request with no time limit; an OOM/timeout fatal skips `up`, leaving maintenance mode on | 173 MB dump → 333 MB peak | **Fixed, merged #59** (`feature/rv-ops-backups`; still in-request by design for shared hosting, now streamed, time limit lifted, fatal-error recovery) |
| RV-OPS-04 | S2 | `ops_snapshots` gains a row every minute, never read or pruned (~525k rows/yr in every backup) | traced | **Fixed, merged #57** (`feature/rv-ops-scheduler-jobs`) |
| RV-PERF-13 | S3 | Spatie permission cache flushed on every request and artisan command (`AppServiceProvider.php:128`) | ~22 ms + 3 queries + a file write per request | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-PERF-14 | S3 | Unbounded full-model user dropdowns (submissions filter, delegate candidates, departments, create-custom) | ~75 B HTML/user | **Partially fixed** (`feature/rv-wave2-s3`: only displayed columns loaded; searchable pickers moved to Wave 4) |
| RV-PERF-15 | S3 | `NotifyCurrentApprovers` re-queries the submission per approver inside the approving request | traced | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-PERF-16 | S3 | `ApplyRetentionJob` offset-chunks while soft-deleting (skips every other page); no job timeout | traced | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-PERF-17 | S3 | Backups page loads 40 snapshots including full files manifests (suspected) | traced | **Fixed, merged #60** (`feature/rv-wave2-s3`; confirmed) |
| RV-OPS-05 | S3 | Nothing prunes notifications, deliveries, failed jobs, activity log, or evidence-pack zips | traced | **Fixed, merged #60** (`feature/rv-wave2-s3`) |
| RV-PERF-18 | S3 | `RetryNotificationDeliveries` sends up to 50 external messages in one job (suspected) | traced | **Fixed, merged #60** (`feature/rv-wave2-s3`; confirmed) |
| RV-PERF-19 | S4 | Minor: ~6 avoidable constant queries per page (system settings LIKE, owner, default theme, unread count twice); Tiptap is 99% of `app.js` but only used on one page; stamp PNGs inlined; builder undo history in Livewire state; heartbeat schema queries; file cache never garbage-collected | — | **Partially fixed** (`feature/rv-wave2-s3`: settings LIKE, duplicate unread count, Tiptap split, file-cache prune; theme/owner queries, stamp PNGs, builder history and heartbeat checks accepted as minor) |

## Wave 3 architecture and Laravel-practice findings (2026-10-17)

Sources: four read-only reviews (large Livewire pages; cross-cutting architecture; Laravel practice; Livewire practice + CSP readiness + leads L9/L10) against `main` at `2b35f7f`. Wave 3 baseline: **1,185 tests** (1,179 passed, 6 skipped) in 1 m 58 s; PHPStan level 7 clean with **82 baseline entries**; Pint clean. Items marked *confirmed* were re-checked by hand before logging.

### Bugs and authorization gaps found by the architecture review

| ID | Sev | Finding | Location | Status |
|---|---|---|---|---|
| RV-SEC-23 | **S2** | *Confirmed.* Announcement banner renders whatever announcement IDs the client sends: `$announcementIds` isn't `#[Locked]` and `announcements()` re-loads by ID without re-checking eligibility, so any signed-in user can read draft, archived, or other-audience announcements | `app/Livewire/AnnouncementBanner.php:18,50-60` | **Fixed, merged #62** (`feature/rv-sec-announcement-ids`: IDs `#[Locked]`; every render re-checks published + audience via `AnnouncementBannerService::stillTargetedAmong`; `AnnouncementBannerTamperTest`) |
| RV-ARC-01 | S3 | *Confirmed.* "Can this user act on this step" has ~6 implementations that disagree. On the request page, the "already decided" check compares enum-cast `action` with strings (`whereIn('action', ['approve',…])`, never matches) and omits ApproveWithConditions, so after deciding on an All/parallel/quorum step the decision panel and Delegate stay visible and the engine then refuses with a message containing the actor's email. `ApproverWorkbench::canAct` (batch approve, delivery retries) skips separation of duties and treats a delegation to a deactivated delegate as held; `SubmissionPolicy::approveViaEmail` (also gates chat) ignores outgoing delegation and "already decided"; two SQL versions (`ReportService::actionRequiredQuery`, `ApproverWorkbench::couldHoldCurrentStep`) differ on delegation | `submissions/show.blade.php:189-261`; `ApproverWorkbench.php:125-175`; `SubmissionPolicy.php:161-180`; `ReportService.php:136-166`; reference: `WorkflowEngine.php:304-341,776-790` | **Fixed, merged #63 and #71** ( new read-only `WorkflowEngine::canAct()`/`canDelegate()` used by the request page and `ApproverWorkbench::canAct`; inbox list mirror applies separation of duties and the active-delegate rule; `ApprovalEligibilityParityTest`). Remainder fixed, merged #71: `ActionableStepConstraint` is the SQL form of `canAct()` used by the dashboard; `approveViaEmail` asks the engine and stale email/chat links explain the refusal; parity test covers engine, page, batch, inbox, SQL and link policy. Known gap: External assignees have no SQL form |
| RV-LAR-02 | S3 | *Confirmed.* Integration payloads read the non-existent `Submission::$reference`, so every HTTP/Slack connector receives `"reference": null` and mail subjects fall back to null; PHPStan reported it but both lines sit in the baseline | `ConnectorHttpClient.php:62`; `Connectors/MailConnector.php:60` | **Fixed, merged #64** (`feature/rv-lar-integration-reference`; 2 baseline entries removed; `test_rv_lar_02_delivered_payload_carries_the_reference_number`) |
| RV-ARC-17 | S3 | Times entered on out-of-office, records, and delegation forms are parsed in the browser's zone (`LocalTime::parse`) but shown back in `APP_TIMEZONE` under "Times are in your time zone"; ~25 inline `format()` calls in 6 patterns; emails format in the app zone | `out-of-office.blade.php:41,124,149`; `records.blade.php:105,245`; `show.blade.php:323,1078` | **Partially fixed** (`feature/rv-s3-css-time`: `LocalTime::display()` on the three forms that read browser-zone input; Help updated; `DelegationLocalTimeTest`). Remainder fix in review (`feature/rv-arc-viewer-time`): `<x-time>` with named formats (`LocalTime::FORMATS`); the browser's zone is remembered in the unencrypted `approvedesk_tz` cookie and used for display and as the form fallback; 13 viewer-facing sites converted; records' datetime-local defaults now in the viewer's zone with the `T` separator; installation-zone screens (reports, exports, backups, operations) unchanged by design; `ViewerTimeZoneTest`. Emails still format in the installation zone |
| RV-LAR-04 | S3 | Web-push VAPID private key stored in `settings` as plaintext (every other settings secret is encrypted) | `WebPushService.php:28-36` | **Fixed, merged #65** (`feature/rv-s3-secrets-delivery`; legacy plaintext key migrated on first read; `WebPushKeyStorageTest`) |
| RV-LAR-06 | S3 | Suspicious-login, account-recovery, and display-name notifications and the registration OTP mail are sent synchronously with no error handling — an SMTP outage turns a successful login or an admin recovery into a 500 | `RecordLoginEvent.php:55`; `RegistrationOtpService.php:56`; `ResetUserPasswordAction.php:57`; `ResetUserSecondFactorAction.php:49`; `DecideDisplayNameChangeAction.php:42` | **Fixed, merged #65** (`feature/rv-s3-secrets-delivery`: three notifications `ShouldQueue` + `afterCommit`; OTP mail stays synchronous, its transport errors were already caught) |
| RV-LAR-07 | S3 | Delivery jobs have no `failed()` handler: a timed-out/killed job leaves its delivery row Pending forever; the integration rate-limit branch throws without counting an attempt, so 5 throttled runs strand the row | `Jobs/DeliverIntegrationAction.php:56,96-114`; `Jobs/DeliverWebhook.php:105-120` | **Fixed, merged #65** (`feature/rv-s3-secrets-delivery`: `failed()` handlers; rate limit releases; `DeliveryJobFailureTest`) |
| RV-LAR-08 | S3 | `BackupDestinationController::update` passes `$request->all()` unvalidated (no lengths, no URL rule); a bad endpoint is a 500 instead of a form error | `BackupDestinationController.php:42` | **Fixed, merged #66** (`feature/rv-s3-input-locks`: Store/UpdateBackupDestinationRequest share rules) |
| RV-LAR-15 | S3 | Record-key properties not `#[Locked]` on 11 pages (each re-authorizes today); `saveEdit` on the request page loads any comment by ID without scoping it to the submission or calling `CommentPolicy::update`; inbox `approvePreview` skips the membership check `openPreview` does; `#[Locked]` on request-types is ignored because the attribute isn't imported (RV-ARC-04) | `show.blade.php:61,477`; `approvals/inbox.blade.php:54,139,150`; `request-types.blade.php:27,50`; `users:33`, `workflow-templates:35`, `workflow-builder:29`, others | **Fixed, merged #66** (`feature/rv-s3-input-locks`: 12 keys locked; comment edit scoped + re-authorized; `LockedRecordKeysTest`) |
| RV-LAR-17 | S3 | CSV import preview has no row cap (XLSX is capped at 5,001) and the whole preview round-trips in Livewire state; builder undo history (40 copies of the steps) travels with every request | `users-import.blade.php:23`; `UserImportService.php:399-407`; `workflow-builder.blade.php:55,500-509` | **Partially fixed** (`feature/rv-s3-input-locks`: CSV capped at 5,000 rows like XLSX, as Help states). Open: preview kept server-side; builder undo history (with the builder split) |
| RV-BUG-39 | S3 | *Confirmed.* Owner custom CSS is printed through `{{ }}`, so `>` and quotes become entities and child selectors/strings break; the one-pass `</style` strip can be rebuilt (`</sty</stylele`). Owner-only input, low risk | `layouts/auth/split.blade.php:11`; `Branding.php:74` | **Fixed, merged #67** (`feature/rv-s3-css-time`) |
| RV-ARC-22 | S3 | Docker/Compose is presented as a supported deployment, but the image only makes `storage/` and `bootstrap/cache` writable, so `/install` (which writes `.env` to the base path) likely fails at requirements; CI never builds the image | `Dockerfile:60`; `Installer.php:66,112-118`; `docs/DEPLOYMENT.md:105-150` | Open — needs a run to confirm. ADR 0013 records Docker as optional/community-supported until CI builds the image and smoke-tests `/install` |

### Architecture (ARC)

| ID | Sev | Finding | Location | Status |
|---|---|---|---|---|
| RV-ARC-02 | S3 | Request page holds domain derivations: timeline state machine, delegation date/delegate checks duplicating the engine, required-comment action list, answer-and-resubmit transaction (also copied, with a different rule, in the API), inline URL signing, status literals, service calls from the template | `show.blade.php:139-186,322-347,382,727-733,749-782` | Open |
| RV-ARC-03 | S4 | Request-page mutators rely on Actions for authorization (all checked and they do); `previewFileId` not locked. Needs one documented convention for who owns authorization | `show.blade.php:74,301-597` | **Fix in review** (`feature/rv-arc-authorization-gates`): every request-page mutator authorizes first (`view` for decide/delegate/take back, where the engine explains refusals; `follow`, `addWatcher`, `comment`, `nudge` for the rest); `previewFileId` locked in #66 |
| RV-ARC-04 | S3 | `#[Locked]` without its import silently resolves to `\Locked` and is ignored (`localDraftDiscarded` writable) | `request-types.blade.php:50` | **Fixed, merged #66** (`feature/rv-s3-input-locks`) |
| RV-ARC-05 | S3 | Request-type builder re-implements the schema compiler's condition evaluation (preview differs: multi-select, missing `in`) and `assertSchemaValid`; `save()` chains four Actions with no transaction (a bad rule leaves a saved draft; a new type is re-created on retry) | `request-types.blade.php:151-161,278-330,373-419,538-665` | Open |
| RV-ARC-06 | S3 | Step mapping and save-time validation duplicated between workflow builder and templates page with different rules; "default must be active" only in one page; role literal `'super-admin'` in the UI | `workflow-builder.blade.php:345-440`; `workflow-templates.blade.php:185-472` | Open |
| RV-ARC-07 | S3 | Workflow builder: `restoreVersion` authorizes only at mount and checks ownership against the unlocked `templateId`; `simulate()` saves a draft first; templates-page `publish()` saves + publishes outside a transaction and does nothing for a new template | `workflow-builder.blade.php:29,90-95,273-321`; `workflow-templates.blade.php:474-497` | Open |
| RV-ARC-08 | S4 | Users page: pending name changes not filtered by `visibleTo` (hidden owner's pending name can show); `selectedManager` unconstrained `find`; phone/manager rules duplicated with the importer | `users.blade.php:33,86-93,128-135,229-241` | **Fix in review** (`feature/rv-arc-split-users-page`): page split per ADR 0006 into `livewire/admin/users/{name-changes,capacity,user-form}` (584 → 155 lines); name review filtered by `visibleTo`; manager lookup `assignable()`; form rules in `UserInputRules` with `E164PhoneNumber`/`AssignableUser` rule objects; editing user loaded once (`#[Computed]`, RV-LAR-18); reject reason is a field, not `prompt()` (a JS global the CSP build blocks; `AlpineCspSafeTemplatesTest` now scans `wire:` actions too); `UsersPageSplitTest` |
| RV-ARC-09 | S4 | Backups page inserts a row on GET (`localDestination()` in `mount`), sums snapshot sizes in PHP, re-implements `hasRemoteReplica`, counts the whole `workflow_actions` table | `backups.blade.php:44,176-200,238,269-278` | **Fix in review** (`feature/rv-arc-split-backups-page`): page split per ADR 0006 into `livewire/admin/backups/{schedule,snapshots,destinations,restore-history}` (526 → 30 lines, sections refresh on `backups-changed`); no write on GET (`BackupRecorder::unsavedLocalDestination()` lists the local disk until the first backup); new `BackupInventory` service sums stored bytes in SQL (`MAX(bytes)` per path), owns `hasRemoteReplica` (shared with `DeleteLocalBackupCopyAction`) and the actions-lost count; the `workflow_actions` count stays a full `COUNT(*)` because the manifest and the promote integrity check record exactly that, but it now runs only when a database snapshot is listed; `BackupsPageSplitTest` |
| RV-ARC-10 | S3 | Custom-code step-up (RV-SEC-16) and allowed-domain validation live only in the branding component; the Action checks only the permission; `mount` reads 15 raw `Setting::get` keys | `settings/branding.blade.php:49-81,133-157,199-225` | **Fix in review** (`feature/rv-arc-split-branding-page`): page split per ADR 0006 (539 → 386 lines) into `livewire/settings/branding/{public-access,custom-code}`. Each child has its own Action: `UpdateRegistrationSettingsAction` with `RegistrationSettingsData`, and `UpdateCustomCodeAction`. `UpdateCustomCodeAction` authorizes and runs the step-up itself (`Services/Auth/StepUpConfirmation`, `StepUpStatus`, `StepUpRequiredException`), and decides when the step-up is needed (the code changes, or an origin is added). Domain and origin validation moved to `Rules/DomainNameList` and `Rules/HttpsOriginList`. `mount` reads through `Branding`/`RegistrationSettings` readers, not raw `Setting::get`. Preview helpers are `protected` (RV-LAR-16). Fixed in passing: the logo preview overwrote `$logo`, so **Keep current logo instead** never showed. Tests: `BrandingPageSplitTest`. |
| RV-ARC-11 | S3 | Controllers bypassing Actions: Telegram `/start` linking (`forceFill` + inline HTTP; untested), integration credentials merged/SSRF-checked inline, API resubmit duplicates the page | `TelegramWebhookController.php:36-47`; `IntegrationCredentialController.php:19-45`; `Api/V1/SubmissionSubmitController.php:22-28` | Open |
| RV-ARC-12 | S4 | No boundary between `app/Support` and `app/Services`: Installer, Turnstile, SeparationOfDuties, SecondFactorAssurance and others in Support do domain/IO work; pure helpers in Services; 45 raw `Setting::get` calls in 17 files outside typed readers | `app/Support/*`, `app/Services/*` | **Move list done, in review** (`feature/rv-arc-support-services-moves`, namespace-only, one commit per area): SeparationOfDuties → Services/Workflow; DnsResolver, BackupTransport → Contracts; Turnstile, SecondFactorAssurance → Services/Auth; ScheduledBackupHealth → Services/Backups; Installer → Services/Install; PublicWebsite → Http; StepConditionForm → Livewire/Forms; RichTextSanitizer, SqlStatementStream, UserImportReadFilter, WorkflowTemplateSchemaHasher → Support. Still open: SystemSettings writes into an Action, raw `Setting::get` calls into readers (as areas are touched) |
| RV-ARC-13 | S3 | 16 Actions take raw arrays; `WorkflowActionData` (named in AGENTS.md) doesn't exist; `processAction` takes 6 positional args, `delegate` 7 | `WorkflowEngine.php:94-101,169-177`; `SaveRequestTypeDraftAction:19`; `SaveWorkflowSelectionRulesAction:21`; settings Actions | Open |
| RV-ARC-14 | S3 | Business rules in jobs/listeners: retention policy in `ApplyRetentionJob` (settings duplicated and written directly by the records page), new-device detection in `RecordLoginEvent`, digest cadence in `SendNotificationDigests` | `ApplyRetentionJob.php:43-77`; `records.blade.php:61,132`; `RecordLoginEvent.php:26-58` | **Fix in review** (`feature/rv-arc-rules-out-of-jobs`): `RetentionSettings` reader (keys, defaults, bounds), `RetentionPolicy` (what is due), `UpdateRetentionSettingsAction` (authorized + activity-logged; the page wrote settings directly with no record), `NewDeviceDetector` + `LoginDeviceCheck`, `DigestSchedule`; jobs and the listener only orchestrate; `RulesOutOfJobsTest`; baseline 67 → 66 |
| RV-ARC-15 | S3 | Status/type columns without enum casts (display-name changes, delivery kind/channel, webhook event, file-revision and role-change actions, …) and status string literals against cast enums in 10+ places; status badge map copied 4 times | see review notes; e.g. `SubmitSubmissionAction.php:33`, `ApprovalReportService.php:86`, `show.blade.php:814`, `index.blade.php:224` | **Fix in review** (`feature/rv-arc-status-enums`): `SubmissionStatus`/`SubmissionPriority` gain `label()`/`color()` (+ `needsRequester()`, `rank()`), one `<x-submission-status-badge>`/`<x-submission-priority-badge>` replaces the 4 + 2 copies (the dashboard copy lacked Awaiting information); new enums + casts for display-name status, file-revision action, role-change action, webhook delivery event; literals replaced in the listed sites. Left as strings on purpose: notification delivery `kind` (free-form for notifications without a `NotificationKind`) and WhatsApp template status (set by Meta) |
| RV-ARC-16 | S4 | Submissions index and admin dashboard use their own visibility rule (own or `submissions.view.all`), ignoring active auditor access in `SubmissionVisibility::seesEverything` | `submissions/index.blade.php:64-69`; `ReportService.php:41-45` | Open |
| RV-ARC-18 | S4 | File-link signing inline in components with hard-coded lifetimes; email and chat approval links use two unrelated schemes in Support and Services | `show.blade.php:749-781`; `backups.blade.php:141`; `EmailApprovalLinks.php`; `ChatActionToken.php` | Open |
| RV-ARC-19 | S3 | Domain exceptions turned into user messages ad hoc at 20+ sites (untranslated developer strings, some with emails); 69 `InvalidArgumentException` throws from Actions caught page by page, 500 where not | `show.blade.php` ×12, `inbox:172`, API controllers; `bootstrap/app.php:48-52` | **Partially fixed** (`feature/rv-arc-domain-exceptions`): `DomainException` base with translated `userMessage()` (no emails or brackets; `getMessage()` kept for logs/tests); global Livewire hook `SupportDomainExceptions` turns uncaught refusals into a toast + `domain` error; `bootstrap/app.php` renders them as 422 JSON or back-with-errors; 22 catch sites show `userMessage()`; `DomainExceptionHandlingTest`. Open: the ~69 `InvalidArgumentException` throws used as user errors in Actions move to a `DomainException` subclass area by area |
| RV-ARC-20 | S3 | Role-name checks outside Policies remain (dashboard mode, auditor flag, user/anonymize Actions, role services, three views) and system-owner gates written in components; no role constants | `ReportService.php:116`; `User.php:243`; `settings/approvals.blade.php:53,98,212`; others | **Fix in review** (`feature/rv-arc-authorization-gates`): `SystemRole` enum for every built-in role name; named Gates `system_owner`, `dashboard.admin`, `approval_reference.manage` replace `hasAnyRole`/`isSystemOwner()` on the acting user in components, services and Actions; target-identity checks and Policies/RBAC services keep `isSystemOwner()` by design (ADR 0008 §4) |
| RV-ARC-21 | S4 | Tenancy-readiness inventory for Wave 5: singletons (owner, site limit, settings, branding, default theme), global cache keys, unscoped storage paths, global reference counter, scheduler sweeps over everything | see review notes | Inventory (Wave 5) |
| RV-ARC-23 | S4 | Decisions without ADRs (lead L9): CSP posture, Livewire component convention, owner/RBAC ceiling, settings caching, backup promote, themes vs branding, deployment-target matrix incl. Docker | `docs/adr/` (0001–0004) | **Done** (`feature/rv-wave3-adrs`): ADRs 0009–0014 record owner/ceiling, settings, backups, branding/themes/custom code, deployment targets, approval IDs; CSP and Livewire convention are 0005–0006 |

### Laravel and Livewire practice (LAR)

| ID | Sev | Finding | Location | Status |
|---|---|---|---|---|
| RV-LAR-03 | S3 | Strict mode is partial: only `preventLazyLoading`; missing-attribute access (would have caught RV-LAR-02) and silently discarded attributes not prevented | `AppServiceProvider.php:266` | Open |
| RV-LAR-05 | S4 | Secret columns: `BackupDestination.config` hand-encrypted (decrypt failure returns `[]`), `PushSubscription.auth_token` plaintext; no `$hidden` on any secret-bearing model | `BackupDestination.php:24-58`; `PushSubscription.php:19`; `WebhookEndpoint.php:34`; `IntegrationConnector.php:38` | Open |
| RV-LAR-09 | S4 | Only 9 Form Requests; 7 controllers validate inline (integration credentials not at all); mixed authorization styles | `IntegrationCredentialController.php:29` and others | Open |
| RV-LAR-10 | S4 | 4 application route closures (cache-safe on Laravel 13); `api/v1/user` checks its own scope; CSV template sample uses a real-looking domain/phone | `routes/api.php:22-26`; `routes/web.php:36,181-189`; `routes/settings.php:36` | Open |
| RV-LAR-11 | S4 | Livewire validation style: inline `$this->validate([...])` is the house style (44 calls); two outliers; some Actions validate with `Validator::make` — document as the convention | — | Open — convention in ADR 0006 (accepted) |
| RV-LAR-12 | S4 | 33 of 64 models have no factory (most used by hand in tests: WebhookDelivery, NotificationDelivery, LegalHold, AuditorAccess, SubmissionFieldValue, SubmissionRevision) | `database/factories/` | Open |
| RV-LAR-13 | S4 | PHPStan baseline: 82 entries / 99 occurrences, level 7. Cheapest: one `@property BackupDestinationKind $kind` clears ~13; RV-LAR-02 clears 2; ~12 mechanical `list<>`/types; ~15 dead defensive code; 26 `nullsafe.neverNull` to review one by one | `phpstan-baseline.neon` | **In progress**: 82 → 80 (#64), → 67 (`feature/rv-arc-status-enums`: typed `BackupDestination::$kind` cleared the 13 false positives) |
| RV-LAR-14 | S4 | Modernisation: little to do (no `switch`, promoted constructors, readonly DTOs, `casts()` everywhere); class constants untyped, no `#[\Override]` | `app/` | Report only |
| RV-LAR-16 | S4 | Public helper methods callable from the client (`fileUrl`, `previewUrl`, `displayComment`, builder `model()`); harmless today because the download controller re-checks | `show.blade.php:679,748,775`; `workflow-builder.blade.php:90` | Open (backups `downloadUrl` is `protected` in `feature/rv-arc-split-backups-page`; the others go with their page splits) |
| RV-LAR-18 | S4 | `#[Computed]` use is sound (89, no persistent cache); users page re-queries the editing user 3× per render | `users.blade.php:457,553,561` | Open |
| RV-LAR-19 | S4 | `.live` without debounce on text inputs/textareas (create-custom, request-type preview, reference prefix) | `create-custom.blade.php:196,210`; `request-types.blade.php:938-966`; `settings/approvals.blade.php:224` | Open (Wave 4 with UX) |
| RV-LAR-20 | S4 | No lazy loading anywhere; dashboard/analytics/operations cards and request-page threads are candidates; notification indicator mounted twice per page | `layouts/app/sidebar.blade.php:134,151` | Open (Wave 4) |
| RV-LAR-21 | S4 | Upload edges: branding preview calls `temporaryUrl()` without `isPreviewable()` (non-image upload crashes render); `preview_mimes` default includes svg; no attachment count cap; evidence verify has no mimes rule | `settings/branding.blade.php:229,402`; `create.blade.php:75`; `records.blade.php:206` | Open |
| RV-LAR-22 | S4 | Component convention: 39 single-file routed pages (8 with ⚡ prefix, 31 without), 3 class widgets, no multi-file components — needs a convention ADR (split ~500 lines, lock record keys, public methods are endpoints) | `resources/views/pages/**`; `app/Livewire/**` | Open — ADR 0006 (accepted) |

### Found while writing the ADRs (2026-10-17)

Raised as open questions in ADRs 0009–0014; each needs a probe test before it is fixed.

| ID | Sev | Finding | Location | Status |
|---|---|---|---|---|
| RV-DATA-10 | **S2** | Promote replaces `backup_snapshots` and `backup_restore_events` with the snapshot's copies, so restore records created after that snapshot are lost (only the promoted and safety rows are written back) — restore evidence should survive a restore | `PromoteDatabaseBackupAction`; ADR 0011 | **Confirmed (S2)** — probe failed on `main`: later backups vanished from the list and from retention, earlier restore records were lost. Fixed, merged #70 (`BackupHistoryCarryOver`; `PromoteKeepsBackupHistoryTest` + MySQL case) |
| RV-OPS-06 | S3 | Promote isn't wrapped in `BackupExecutionGuard` (only the MySQL safety backup takes the lock), so a scheduled backup or a second promote can overlap the SQLite and files promotes | `app/Actions/Backups/Promote*`; ADR 0011 | **Confirmed (S3)** — both promotes ran while a backup held the lock. Fixed, merged #70 (promotes run in `BackupExecutionGuard`, now re-entrant for the MySQL safety backup) |
| RV-SEC-24 | S4 | `BrandingAssetController` still serves `.svg` and `.gif` although branding uploads accept only JPG/PNG/WebP/ICO (SVG excluded as an XSS risk); a file placed there another way (e.g. a files restore) is served same-origin | `BrandingAssetController.php:20-22` | Open |
| RV-DOC-03 | S4 | Docs drift: AGENTS.md says reference numbers use "a retry loop around the unique constraint" (code uses a locked counter / reservation loop); DEPLOYMENT §2 omits that the installer needs the `.env` directory writable; `.env.example`/`config/cache.php` default `CACHE_STORE=database` (installer writes `file`); `Setting` docblock says "per key"; IMPLEMENTATION_PLAN Phase BK says `mysqldump`, Drive service accounts, `settings.manage` (code: PHP dump, OAuth token, `backups.promote`); `users-and-roles` Help says non-owners can edit an administrator's name/phone, `UpdateUserAction` refuses any edit of an admin by a non-owner with a misleading message | various; ADRs 0009–0014 | Open |
| RV-PERF-20 | S4 | `BackupSettings::value()` runs `Schema::hasTable('settings')` on every read; theme resolution runs 1–2 uncached `themes` queries per signed-in page | `BackupSettings.php`; `ThemeService::effectiveFor` | Open |
| RV-OPS-07 | S4 | Retention never prunes `Failed` backup rows; `SystemSettings` is bound with `app()->instance()`, so a long-lived worker may keep old mail/OAuth config (untested; the scheduled worker exits after 50 s) | `RetainBackupSnapshotsAction`; `SystemSettings` | Open |
| RV-TEST-06 | S4 | CI runs PHP 8.4 only, although 8.3–8.5 are supported (`^8.3` would also admit 8.6) | `.github/workflows/ci.yml` | Open |

### CSP (RV-SEC-15, deferred from Wave 1)

Inventory: one app inline `<script>` (`settings/notifications.blade.php:120`), four inline `on*` handlers (`users.blade.php:568,574`, `reports/approval.blade.php:73`, `install/database.blade.php:17`); Livewire and Flux scripts accept `Vite::cspNonce()` but nothing calls `Vite::useCspNonce()`, and `@fluxAppearance` needs the nonce passed. Livewire 4.4 ships an Alpine CSP build (`csp_safe`), but ~15 inline `x-data`/handler expressions use arrows or globals and the `window.*` factories in `resources/js/app.js` would need `Alpine.data`; Flux has no CSP mode. No third-party origins except Turnstile; owner custom code adds any `https://` host it mentions (including comments) to four directives; no CSP reporting. **ADR 0005 (accepted)** — enforce a nonce-based `script-src` while keeping `'unsafe-eval'` (Option A), ship the strict CSP-safe policy as `Content-Security-Policy-Report-Only` with a throttled report endpoint (Option C), and enforce the strict policy (Option B) only once reports are clean; keep `style-src 'unsafe-inline'`; replace host extraction with an explicit allow-list.

---

## Leads awaiting verification (from REVIEW_PLAN §4)

| Lead | Track | Wave | Status |
|---|---|---|---|
| L1 `json_extract` comparison in `AssignedStepConstraint` may never match on MariaDB | BUG/DATA | 1 | Confirmed → RV-BUG-02 |
| L2 Oversized Livewire page components | ARC | 3 | Reviewed → RV-ARC-01…11 |
| L3 Authorization only at route level in 26 pages; 12 `#[Locked]` properties | SEC | 1 | Reviewed → RV-SEC-06, RV-SEC-07, RV-SEC-11; other pages authorize per action |
| L4 13 raw `{!! !!}` outputs | SEC | 1 | Reviewed → RV-SEC-03 (fixed); theme CSS, Help, QR found safe |
| L5 `hasRole` outside Policies (7 files) | ARC/SEC | 1 | Reviewed → RV-SEC-04, RV-SEC-18 |
| L6 Per-key `rememberForever` settings on file cache | PERF | 2 | **Fixed (on `main`)**: one `settings.all` entry, memoised per request; also fixed caching of the first caller's default for missing keys |
| L7 40 `lockForUpdate()` calls outside the engine | ENG | 1 | Reviewed: all transactional; inversions → RV-ENG-05 |
| L8 Engine coverage target | TEST | — | → RV-TEST-04 |
| L9 Missing ADRs | DOC | 3 | Reviewed → RV-ARC-23 |
| L10 Docker files vs shared-hosting constraint | ARC | 3 | Reviewed → RV-ARC-22 (nothing in the app depends on Docker; the image path is likely broken) |
