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
- Severity: **S2** · Status: Open
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
- Severity: S3 · Status: Open · Location: `resources/views/pages/dashboard.blade.php` · Evidence: 43 → 70 queries for 3 → 30 submissions.

### RV-PERF-02 — Approvals inbox issues ~1 query per pending item
- Severity: S3 · Status: Open · Location: `resources/views/pages/approvals/inbox.blade.php` · Evidence: 21 → 48 queries.

### RV-PERF-03 — Submission detail issues ~2 queries per comment; lazy-loads `Comment::commentable`
- Severity: S3 · Status: Open · Location: `resources/views/pages/submissions/show.blade.php` · Evidence: 52 → 106 queries for 3 → 30 comments; lazy-load violation `Comment::commentable`.

### RV-PERF-04 — Analytics query count grows with submissions
- Severity: S3 · Status: Open · Location: `resources/views/pages/admin/analytics.blade.php`, `app/Services/AnalyticsService.php` · Evidence: 23 → 50 queries.

### RV-PERF-05 — Notifications centre issues ~2.4 queries per notification
- Severity: S3 · Status: Open · Location: `resources/views/pages/notifications/index.blade.php` · Evidence: 37 → 103 queries.

### RV-TEST-03 — Query budget tests cannot detect row-level N+1
- Severity: S3 · Status: Open · Location: `tests/Feature/QueryBudgetTest.php`
- Evidence: fixed budgets measured at 5–20 rows; none of RV-PERF-01…05 trips them.
- Fix: Wave 2 — assert equal query counts at two dataset sizes per page, and cover the five pages above.

### RV-LAR-01 — No lazy-loading / strict-model guard outside production
- Severity: S3 · Status: Open · Location: `app/Providers/AppServiceProvider.php`
- Fix: `Model::shouldBeStrict(! app()->isProduction())` (or at least `preventLazyLoading`) after RV-PERF-03 is fixed.

### RV-TEST-04 — `WorkflowEngine` coverage is only just above its gate
- Severity: S3 · Status: Open — **decision pending** (REVIEW_PLAN §11 Q4) · Evidence: 97.10% vs a 97% gate; AGENTS.md states 100%.

### RV-DATA-01 — MariaDB 10.6 is supported but untested
- Severity: S2 · Status: **Fixed** (CI job added in PR #23) · Location: `.github/workflows/ci.yml` (`mariadb` job)
- First run: 882 of 949 tests fail, all from one cause → RV-DATA-02.

### RV-DATA-02 — Migrations fail or silently corrupt timestamps on MariaDB 10.6–10.9
- Severity: **S1** · Status: Open — **decision needed** (fix requires editing released migrations)
- Location: 10 `Schema::create` blocks with `NOT NULL TIMESTAMP` columns (`timestamp('x')` with no `nullable`/`useCurrent`/`default`).
- Evidence: CI `mariadb` job — `1067 Invalid default value for 'expires_at'` on `create table delegations`. MariaDB < 10.10 defaults to `explicit_defaults_for_timestamp=OFF` (global-only, not settable per session before 10.10), so:
  - the **first** such column in a table silently becomes `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`;
  - any **second** one gets a zero-date default, which Laravel's strict mode rejects.
- Impact:
  - **Install-breaking (2+ columns):** `delegations` (starts_at, expires_at), `user_out_of_office` (starts_at, ends_at), `auditor_accesses` (starts_at, ends_at). A fresh install on MariaDB 10.6–10.9 — common on cPanel — stops at `2026_08_17_120000`.
  - **Silent overwrite (1 column):** `workflow_instances.started_at`, `workflow_actions.created_at`, `submission_nudges.scheduled_for`, `registration_otp_codes.expires_at`, `legal_holds.placed_at`, `ops_snapshots.recorded_at`, `ops_backup_verifications.verified_at` would be reset to "now" on every row update — e.g. `started_at` on every approval step, `placed_at` when a hold is released. Audit-evidence corruption.
  - No working MariaDB < 10.10 installation can exist today (installs fail at `delegations`), so changing these migrations creates no drift for real installs. MySQL 8, MariaDB ≥ 10.10 and SQLite are unaffected.
- Proposed fix: in the affected create migrations, give these columns an explicit definition that disables the implicit behaviour only when the server is MariaDB with `explicit_defaults_for_timestamp=OFF` (schema on MySQL/SQLite unchanged); add an installer requirements check that reports the setting; regression test that asserts no `NOT NULL TIMESTAMP` column is declared without an explicit default.

---

## Leads awaiting verification (from REVIEW_PLAN §4)

| Lead | Track | Wave | Status |
|---|---|---|---|
| L1 `json_extract` comparison in `AssignedStepConstraint` may never match on MariaDB | BUG/DATA | 1 | Open — the new MariaDB CI job should expose it if tests cover form-field assignees |
| L2 Oversized Livewire page components | ARC | 3 | Open |
| L3 Authorization only at route level in 26 pages; 12 `#[Locked]` properties | SEC | 1 | Open |
| L4 13 raw `{!! !!}` outputs | SEC | 1 | Open |
| L5 `hasRole` outside Policies (7 files) | ARC/SEC | 1 | Open |
| L6 Per-key `rememberForever` settings on file cache | PERF | 2 | Open |
| L7 40 `lockForUpdate()` calls outside the engine | ENG | 1 | Open |
| L8 Engine coverage target | TEST | — | → RV-TEST-04 |
| L9 Missing ADRs | DOC | 3 | Open |
| L10 Docker files vs shared-hosting constraint | ARC | 3 | Open |
