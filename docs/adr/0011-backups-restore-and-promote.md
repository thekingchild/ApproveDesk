# 0011. In-app backups, integrity-checked verify, and in-request promote

Date: 2026-10-17

Status: Accepted (recorded retroactively; decision in force since v2.5.4 (Phase BK). SQLite promote of SQL dumps since v2.7.1, MySQL promote and checksum checks since v2.7.2, safety snapshot, streaming, and fatal-error recovery since Unreleased (RV-DATA-03, RV-OPS-03))

## Context

Administrators on shared hosting wanted to take, download, and put back copies of their data without SSH, `mysqldump`, or a support ticket to the host. The environment rules out the usual tools: no Docker, no Redis, no long-running worker, often no shell, one cron entry, and a host-imposed request time limit. A restore also touches `workflow_actions`, which the application otherwise treats as append-only, so it has to be deliberate, verified, and recorded. Host panel backups (JetBackup, Plesk) remain the primary restore source. In-app snapshots complement them.

## Decision

- **Two separate kinds.** A database snapshot (`CreateDatabaseBackupAction`) is a gzip of a PHP-written SQL dump: `CREATE TABLE` from `sqlite_master` or `SHOW CREATE TABLE`, then one `INSERT` per row, covering every table except `migrations`. All tables are read in one transaction so the dump is a single point in time (RV-DATA-04). A files snapshot (`CreateFilesBackupAction`) is a zip of `submissions/` on the local disk and `branding/` on the public disk. The two kinds are never combined.
- **Manifests and digests.** The database manifest holds per-table row counts and the `workflow_actions` count. The files manifest lists each entry's path and SHA-256, and the zip also embeds `manifest.json`. `BackupRecorder::store()` records the archive's byte size and SHA-256 and names it with the date and time it was taken in `APP_TIMEZONE`.
- **Destinations.** The local private disk (`storage/app/private/backups/{database,files}/`) is always written first. Each enabled, configured S3-compatible or Google Drive (OAuth refresh token) destination then receives a copy. The upload must be listed afterwards, and each remote copy gets its own `backup_snapshots` row. A failed remote records a `Failed` row and the destination's last error, and does not fail the local copy. Destination credentials are encrypted on `backup_destinations.config`. S3 endpoints must pass the outbound HTTPS/public-address check and are pinned. A local copy may be removed only when a remote copy exists.
- **One backup at a time.** `BackupExecutionGuard` takes a two-hour cache lock. The scheduler also runs `backups:database` and `backups:files` with `withoutOverlapping(120)` at the configured times (defaults 02:30 and 02:45).
- **Verify against the recorded digest first.** `MaterializeBackupSnapshotAction` uses the local file or fetches the remote one into a temporary file. It then requires the exact recorded size and SHA-256 (`BackupIntegrity::assertMatches`) before any content check. Database dumps are gunzipped to a scratch file and checked by `BackupVerifier` for the required tables. Files zips must match the manifest exactly: no extra, missing, duplicate, absolute, `..`, symlink, or directory entries, at most 10,000 entries and 2 GiB uncompressed, and every hash must match. Success sets `Verified` and `verified_at`.
- **Promote** needs `backups.promote`, a previously verified snapshot, the snapshot number typed as confirmation, and a fresh re-verify:
  - *SQLite:* build a scratch database from the dump (or copy a raw SQLite file), check it and its `workflow_actions` count, then enter maintenance mode, keep the live file at `<db>.pre-promote`, copy the scratch file over the live one, and come back up.
  - *MySQL/MariaDB:* DDL is not transactional, so enter maintenance mode, then take and verify a new **safety snapshot**. `MysqlDumpLoader` drops every application table and replays the dump statement by statement, then the result is checked against the manifest. On failure the safety snapshot is reloaded. If that also fails, the site stays in maintenance mode and the error names the safety snapshot.
  - *Files:* extract the verified entries into `restore-holding/`, then move the live `submissions/` and `branding/` trees aside to `*-previous-<timestamp>` on the private disk and move the restored ones in. Replaced trees are never deleted. Branding is left alone when the snapshot has none.
  - Afterwards the settings and permission caches are flushed. The promoted row and the safety row are written back, since their table was replaced. A `backup_restore_events` row records the actor, the safety snapshot, and how many later workflow actions were discarded, and the read-only reconciler runs. Promote never calls the `WorkflowEngine`.
- **It runs inside the web request.** There is no worker to hand it to. `LongRunningOperation::prepare()` lifts PHP's time limit and ignores client disconnects. Dumps, gzip, Drive transfers, and SQL replay stream in 1 MB pieces (`SqlStatementStream`). `LongRunningOperation::onFatal()` registers a shutdown handler for fatal errors (out of memory, host kill), because those skip `finally`. If the SQLite promote dies, the handler restores the `.pre-promote` file and brings the site up. If the MySQL promote dies before loading starts, it brings the site up. After loading starts, it stays down and logs `backup.promote_interrupted` with the safety snapshot ID.
- **Retention.** `backups:retain` runs at 04:15. It deletes expired non-failed snapshots (database 14 days, files 7 days by default, each clamped to 3–90) and their files or remote objects. The newest successful snapshot of each kind is always kept.

## Consequences

- Operators can back up and restore from the browser on hosts with no shell. Large installs are still bounded by the host's request limit (for example a 300-second `request_terminate_timeout`), so `docs/DEPLOYMENT.md` recommends scheduled or SSH-run backups there.
- Promote replaces `workflow_actions` wholesale with older contents. This is the one sanctioned path that removes recorded actions. It is gated, typed-confirmed, and logged, and the discarded count is recorded.
- A tampered or swapped archive, local or remote, is refused before its contents are parsed.
- The PHP dump is portable between SQLite and MySQL, but slower than native tools and not a general-purpose MySQL dump.
- Manual cleanup is needed: `*-previous-*` trees and `<db>.pre-promote` stay on disk until an operator removes them.

## Open questions

- The plan's locked decisions (IMPLEMENTATION_PLAN.md, Phase BK) say "PHP table dump when `mysqldump` is absent" and "SQLite is a consistent file copy". The code always writes a PHP SQL dump on both drivers and never calls `mysqldump`.
- BK-DST-03 lists Google Drive service-account JSON. `GoogleDriveBackupTransport` accepts only an OAuth refresh token. BK-RST-04 names `settings.manage` for promote. The code uses `backups.promote` (since v2.7.0).
- Promote replaces `backup_snapshots` and `backup_restore_events` with the snapshot's copies. Restore history and snapshot rows created after that snapshot are lost, apart from the rows written back. Is that intended?
- Promote is not wrapped in `BackupExecutionGuard`. Only the safety backup inside a MySQL promote takes the lock, so a scheduled backup or a second promote can overlap the SQLite path or a files promote.
- Retention never prunes `Failed` rows, so failed remote attempts accumulate.
- SQLite promote takes no separate safety snapshot. It relies on the `.pre-promote` file copy.
