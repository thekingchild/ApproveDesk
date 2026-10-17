# Operations runbook

Operator procedures for reliability: logs, backups, dead-letter replay, stuck workflows, performance budgets, and zero-downtime releases. Product Help at `/help/operations` covers the administrator screen only.

ApproveDesk stays shared-hosting compatible: database queue, file cache, one cron entry (`php artisan schedule:run` every minute). Do not introduce Redis or a long-running worker as a requirement.

## 1. Structured logs and correlation IDs

Every HTTP response includes `X-Correlation-ID`. Clients may send the header; malformed values are replaced. The same id is stored on queued job payloads and shared into log context as `correlation_id`.

The default log channel is unchanged. A JSON channel is available:

```
LOG_STACK=single,structured
```

JSON lines are written to `storage/logs/structured.log`.

## 2. Scheduler heartbeat and queue metrics

`ops:heartbeat` runs every minute and writes:

- `ops.scheduler_heartbeat_at` in settings
- a row in `ops_snapshots` (pending jobs, failed jobs, dead letters, stuck workflows)

The **Administration → Operations** screen reads the live counts plus the last heartbeat. A heartbeat older than five minutes is shown as stale. Check it without creating a heartbeat or running scheduled work:

```
php artisan ops:scheduler-check
php artisan schedule:list
php artisan ops:budget-report
```

`ops:scheduler-check` returns failure for a missing/stale heartbeat and prints the configured database/files backup times. **Backup now** is manual and does not prove cron health. On shared hosting, one host cron must call `php artisan schedule:run` every minute. A local development process may use `php artisan schedule:work`, but it must be restarted after reboot unless a process manager owns it.

## 3. Backup verification and restore drill

Host backups (JetBackup, Plesk Backup Manager, volume snapshots) remain the source of restore. In-app snapshots on **Administration → Backups** complement them. **Promote** on that screen puts a verified database snapshot back on a SQLite file or a MySQL/MariaDB copy. `ops:restore-drill` still never writes a backup over the live database.

```
php artisan backups:database
php artisan backups:files
php artisan backups:retain
php artisan ops:backup-verify
php artisan ops:backup-verify /path/to/backup.sqlite
php artisan ops:backup-verify /path/to/dump.sql
php artisan ops:restore-drill
php artisan ops:restore-drill /path/to/backup.sqlite
```

`ops:restore-drill` copies the live SQLite file (or verifies the supplied file), checks required tables, compares counts when it made the copy, and refuses to continue if `workflow_actions` changed during the drill.

Required tables: `users`, `submissions`, `workflow_instances`, `workflow_actions`, `settings`, `jobs`, `failed_jobs`, `submission_files`.

### Restore drill (per release or quarterly)

1. Take a host backup of the database **and** `storage/app`.
2. Restore that backup to a scratch database or a copied SQLite file. Do not point the live `.env` at the scratch copy.
3. Run `php artisan ops:restore-drill /path/to/scratch`.
4. Confirm the command reports success and that live `workflow_actions` count is unchanged.
5. Open `/up`, `/login`, and one request. Download one evidence pack and run `php artisan evidence:verify {path}`.
6. Record the result. The Operations screen also keeps the last in-app **Check this copy**.

## 4. Dead-letter management and safe replay

Failed webhooks, integration dead letters, failed notices, and `failed_jobs` appear on Operations. Replay is allowed only for failed/dead-letter items. Delivered items are refused. Replay does not insert a second delivery row and does not call `WorkflowEngine`.

```
php artisan ops:replay webhook {id}
php artisan ops:replay integration {id}
php artisan ops:replay notification {id}
php artisan ops:replay job {uuid}
```

## 5. Stuck workflows and reconciliation

```
php artisan ops:diagnose-stuck
php artisan ops:reconcile
```

Stuck: `workflow_instances.status = active` and (`current_step_id` is null or `step_started_at` is older than 24 hours).

Reconcile reports only:

- submitted / in-review / awaiting-information requests with no instance
- active instances with no current step
- delivered webhook or integration rows that still have `next_attempt_at`

It does not create, update, or delete workflow records.

## 6. Performance budgets

See [PERFORMANCE.md](PERFORMANCE.md). CI enforces query budgets and index presence. The 100k-submission / 1m-action targets are documented capacities, not rows created in PHPUnit.

```
php artisan ops:budget-report
```

## 7. Zero-downtime-compatible migrations

Follow the expand → backfill → validate → constrain → contract sequence in `IMPLEMENTATION_PLAN.md` §6.

```
php artisan migrate:preflight
```

The command fails if a **pending** migration drops or renames a column/table. Additive `CREATE TABLE` / nullable columns / indexes are allowed.

Administrators can run the same check and apply from **Administration → Updates** (type APPLY). That screen does not replace files and never calls `migrate:rollback`.

Do not run `php artisan migrate:rollback` in production. `workflow_actions` is append-only; rolling back a migration that touched history is unsafe.

## 8. Content Security Policy reports

Browsers report Content Security Policy violations to `POST /csp-report` (ADR 0005). Each report becomes one line in `storage/logs/csp-YYYY-MM-DD.log`, kept 14 days. A line holds the page and blocked address without query strings, the directive, and whether the policy blocked (`enforce`) or only reported (`report`). The endpoint is throttled to 30 reports a minute per address and refuses bodies over 8 KB.

Reports from the enforced policy usually mean owner custom code uses inline event attributes, or something injected a script. Investigate them.

**Moving toward the strict policy (ADR 0005 steps 2–3).** Set `LIVEWIRE_CSP_SAFE=true` on a staging site. Livewire then serves Alpine's CSP build, and ApproveDesk adds `Content-Security-Policy-Report-Only` without `'unsafe-eval'`. Use the screens people use, then read the CSP log: `report` lines are what the strict policy would still block. Livewire keeps the same script address for both builds, so browsers that cached the standard build keep using it until their cache expires. Clear the browser cache when testing, and plan a cache-busting release before step 3 enforces the policy.

## 9. Release rollback

1. Keep the previous release tag and its `public/build` assets.
2. Restore the previous release files from that tag or from a backup zip (rsync or the panel file manager). Do not expect GitHub Actions to deploy the previous tag.
3. Do **not** `migrate:rollback`. If the new release added tables, leave them; they are unused. If it added a required column, ship a forward corrective migration on a hotfix tag instead of rolling the schema back.
4. `php artisan migrate:preflight` then `php artisan migrate --force` only for the hotfix.
5. `php artisan optimize:clear` then `config:cache`, `route:cache`, `view:cache`.
6. Smoke: `/up`, `/login`, submit, approve, attachment download, `ops:heartbeat`, `evidence:verify` on one pack.

See also [CI-CD.md](CI-CD.md) §9 and [DEPLOYMENT.md](DEPLOYMENT.md) §9.
