# Deployment Guide — Shared Hosting

How ApproveDesk reaches a server and gets configured. Routine releases are automated by CI ([CI-CD.md](CI-CD.md)); this document covers **server bring-up** and the **installer wizard**, plus the one-time host configuration. Original spec sections 8–9 remain the reference; this guide supersedes them where they conflict (Laravel 13/PHP 8.3, installer-first setup).

## 1. Server Requirements

| Requirement | Spec |
|---|---|
| PHP | 8.3+ with: BCMath, Ctype, cURL, DOM, FileInfo, JSON, Mbstring, OpenSSL, PCRE, PDO, Tokenizer, XML, ZIP, GD; pdo_mysql |
| Database | MySQL 8.0+ / MariaDB 10.6+ |
| Web server | Apache/Nginx+PHP-FPM, or the supplied container |
| Scheduler | 1 cron entry per minute on shared hosting; managed inside the container |
| SSL | Let's Encrypt (AutoSSL / Plesk SSL/TLS) |
| Disk / RAM | 2 GB / 512 MB minimum; 10 GB / 2 GB recommended |
| SSH | Required for automated CI deploys (jailed shell acceptable); FTP-only hosts use the manual fallback |

## 2. First Run — The Web Installer (`/install`)

On a fresh deploy the app redirects to `/install`. No SSH or artisan knowledge needed for setup:

1. **Welcome & requirements check** — PHP version, extensions, writable `storage/` + `bootstrap/cache/`, DB drivers; green/red per item, blocks progress until greens.
2. **Database** — enter host/database/user/password (created beforehand in cPanel/Plesk). Installer tests the connection, writes `.env`, generates `APP_KEY`, runs `migrate --force`, seeds roles/permissions/settings.
3. **First admin** — create the super-admin account (name, email, password).
4. **Finish** — lock file `storage/app/installed.lock` is written; **every** `/install` URL (including `/install/done`) 404s from then on. Redirect to the branded login page, which shows a one-time “ApproveDesk is ready” message.

Security notes for operators:
- The `.env` must be web-writable during install — after finishing, tighten: `chmod 644 .env` (or 400).
- The installer middleware also honors `APP_INSTALLED=true` for environments where the lock file can't be written.
- Re-running setup requires deliberately deleting the lock file (documented recovery path for broken installs only).

Office hours, backup clocks, snapshot names, and scheduler display use `APP_TIMEZONE` (IANA name, default `UTC`). Example: `APP_TIMEZONE=Africa/Lagos`. The installer writes `UTC` when the key is missing and does not overwrite an existing value. Changing to a DST zone must keep calendar math on `setTime`, never `addHours(24)`.

Custom S3-compatible backup endpoints must be public HTTPS. For a private MinIO (or similar) on your network, set `BACKUP_ALLOW_PRIVATE_S3_ENDPOINT=true`. Leave it unset in production internet-facing installs.

## 3. cPanel Bring-Up (once per server)

**Database:** cPanel → MySQL Databases → create DB + user, grant privileges. Restrict later per §9.

**Files (first time, manual; later automated by CI):**
1. Build locally or pull the CI release bundle (`vendor/` and `public/build/` included — cPanel needs no Node/Composer).
2. Upload to `/home/<user>/approvedesk/` — **outside** `public_html`.
3. Copy the contents of `public/` into `public_html/` (or the subdomain docroot) and point `public/index.php` at the app:
   ```php
   require __DIR__.'/../approvedesk/vendor/autoload.php';
   $app = require_once __DIR__.'/../approvedesk/bootstrap/app.php';
   ```
4. Ensure `storage/` and `bootstrap/cache/` are writable (755/775 as the host requires).
5. Visit the domain → `/install` wizard (§2).

**Cron (required):** cPanel → Cron Jobs → every minute:

```
* * * * * /usr/local/bin/php /home/<user>/approvedesk/artisan schedule:run >> /dev/null 2>&1
```

Find the right PHP binary via MultiPHP Manager (e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`). The scheduler itself launches the queue worker on shared hosting (`routes/console.php`). Verify it without running scheduled work:

```bash
php artisan ops:scheduler-check
```

A healthy result means the heartbeat is no more than five minutes old. A stale/missing result means the one-minute cron or its PHP binary needs attention. **Backup now** does not test cron. Backup, verify, and promote run inside the web request with PHP's time limit lifted and stream files in 1 MB pieces; a host-level request limit (for example a 300-second LiteSpeed or PHP-FPM `request_terminate_timeout`) still applies, so on large installations prefer the scheduled backups and run `php artisan backups:database` over SSH when available. A files promote moves the replaced trees aside rather than deleting them: request attachments to `storage/app/private/submissions-previous-<timestamp>/` and branding artwork to `storage/app/private/branding-previous-<timestamp>/` (outside the public web folder). Remove them once the restored copy has been checked.

```php
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --timeout=90')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();
```

The worker exits after 50 seconds, so ticks never stack, and its overlap lock expires after 5 minutes if the host kills it. Keep `DB_QUEUE_RETRY_AFTER` (default 150) above `--timeout`, or a slow job is handed to a second worker while the first is still running. Scheduled jobs (escalations, out-of-office fallbacks, delivery retries, digests, retention) are unique: a copy is not queued while another is waiting or running. Operations snapshots older than 14 days are pruned daily, and expired file-cache entries are deleted daily (`cache:prune-file-cache`). Role and permission data is cached; it is refreshed automatically when roles change in the app and after migrations. If you edit the `roles`/`permissions` tables by hand, run `php artisan permission:cache-reset`. The chat delivery retry job sends at most 20 held or failed messages per run and stops starting new sends after 45 seconds, so it stays inside the 90-second worker timeout; a larger backlog drains over successive 15-minute runs.

Nightly housekeeping (03:30–03:40) also prunes read in-app notifications older than 180 days, notification delivery attempts older than 180 days (except those about a request under an active legal hold), failed queue jobs older than 30 days (`queue:prune-failed --hours=720`), and generated evidence-pack zips older than 30 days (`evidence:prune-files`; the `evidence_packs` rows and export log are kept, and every download builds a fresh zip). The activity log is audit evidence and is **not** pruned; if it must be trimmed, run `php artisan activitylog:clean --days=N` deliberately after checking your retention policy. Unread notifications and `workflow_actions` are never pruned.

Retention (`ApplyRetentionJob`, daily 04:15) **soft-deletes** requests: they leave every screen but the row, its workflow history, comments and files stay in the database as audit evidence, and nothing purges them. The job stops taking new rows after about 55 seconds and continues on the next run, so a large first backlog clears over several days. To bring a retired request back, set its `submissions.deleted_at` to `NULL`.

**Optional .htaccess hardening** (force HTTPS, protect sensitive paths):

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## 4. Plesk Bring-Up (once per server)

1. Websites & Domains → Add subdomain/domain; docroot `httpdocs/public`; PHP 8.3 FPM; Let's Encrypt on.
2. **Git deployment:** Plesk → Git → connect the repo; deploy path `/var/www/vhosts/<domain>/approvedesk`; enable the post-deploy webhook so CI's SSH nudge (see [CI-CD.md §6](CI-CD.md)) pulls automatically. Docroot then serves `approvedesk/public` (set via symlink or vhost config).
3. **Scheduled task:** Tools & Settings → Scheduled Tasks → every minute:
   ```
   /opt/plesk/php/8.3/bin/php /var/www/vhosts/<domain>/approvedesk/artisan schedule:run
   ```
4. Nginx additional directives: `client_max_body_size 50M;` (uploads) and the standard `try_files … /index.php?$query_string` front-controller block.
5. Visit the domain → `/install` wizard (§2).

## 5. Cloudflare proxy/CDN

Cloudflare may proxy an ApproveDesk hostname, but it must not rewrite or cache the interactive application shell.

1. Set **SSL/TLS encryption mode** to **Full (strict)**. Do not use Flexible SSL. Install a valid certificate (Let's Encrypt or a Cloudflare Origin Certificate) at cPanel/Plesk and set `APP_URL=https://approvals.example.com` to the exact public origin, with no trailing slash.
2. Create a Cloudflare **Configuration Rule** for the ApproveDesk hostname and turn **Rocket Loader off**. ApproveDesk rewrites Vite, Livewire, and Flux script tags so `data-cfasync="false"` appears before `src` as a defensive fallback, but the hostname rule is still recommended.
3. Do not use **Cache Everything** for the app hostname. If custom cache rules exist, bypass cache for HTML and at least `/install*`, `/login*`, `/logout`, `/livewire*`, `/flux*`, `/dashboard*`, `/admin*`, `/approvals*`, `/submissions*`, `/profile*`, and `/api*`. Static files under `/build/assets/*` may be cached normally.
4. After installing or upgrading, purge the Cloudflare cache. If navigation renders but the main panel is blank, temporarily switch the DNS record to **DNS only**. If that fixes it, inspect Rocket Loader and cache rules before restoring the proxy; do not reinstall or change databases.

Cloudflare **Turnstile** in Administration → Security & privacy is independent of the DNS/CDN proxy. Removing the proxy does not disable a configured Turnstile challenge, and disabling Turnstile does not change Cloudflare caching.

## 6. Docker and container platforms (not supported)

ApproveDesk supports shared hosting (sections 1–5) only. Docker, Docker Compose, EasyPanel, Coolify, and similar container platforms are **not supported** (ADR 0013).

The `Dockerfile`, `compose.yaml`, and `docker/` files in the repository are left over from earlier releases. They are not built or tested in CI, and the web installer probably cannot write `.env` inside that image (RV-ARC-22). Do not use them for a production installation. Problems reported on them are not fixed.

## 7. Scheduler checks

```bash
php artisan ops:scheduler-check       # read-only heartbeat/backup schedule check
php artisan schedule:list             # expected scheduled commands and next due times
```

A local development copy may use `php artisan schedule:work`, but that foreground/background process must be restarted after reboot. Production/shared hosting should use the one-minute `schedule:run` cron above.

## 8. Post-install checklist (per server, once)

- [ ] `/up` health route returns 200 over HTTPS
- [ ] Cron firing (check `storage/logs/schedule.log` or scheduler output)
- [ ] Queued emails delivering (send a test approval round-trip)
- [ ] Uploads working and served via signed URLs (not direct paths)
- [ ] `php artisan storage:link` run if using the public disk
- [ ] Installer locked (every `/install` URL → 404)
- [ ] After a code deploy, **Administration → Updates** shows no unsafe pending migrations; apply only after a host backup
- [ ] `.env` permissions tightened; `APP_DEBUG=false`
- [ ] Backups scheduled (JetBackup / Plesk Backup Manager) **and a restore drill done**
- [ ] **Administration → Backups** has a local database snapshot and a files snapshot after first use. In-app Verify compares the file to the recorded size and SHA-256 before any table or ZIP checks, so a replaced remote object that still looks like a dump is refused.
- [ ] Admin accounts have 2FA enabled
- [ ] Runtime security verification in §12 is complete (web-push pinning, installer ingress allow-list, `installed.lock`)
- [ ] The installer-created System owner exists (`php artisan tinker` → `App\Models\SystemOwner::query()->first()`). If ownership is missing after an upgrade, run `php artisan owner:assign {email}` once. This command will not overwrite an existing owner.

## 9. Hardening the database user (audit integrity)

After installation, reduce the MySQL user's privileges:

```sql
-- Application user: no DDL, and append-only on the audit table
REVOKE ALL PRIVILEGES ON approvedesk.* FROM 'app_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON approvedesk.* TO 'app_user'@'localhost';
REVOKE UPDATE, DELETE ON approvedesk.workflow_actions FROM 'app_user'@'localhost';
FLUSH PRIVILEGES;
```

This makes the immutability of `workflow_actions` a database-level guarantee, per the compliance requirement in the spec. Use a separate read-only user for reporting exports if Phase 3 analytics get heavy.

## 10. Routine releases & rollback

Handled by CI — see [CI-CD.md §6–9](CI-CD.md) and the full runbook in [OPERATIONS.md](OPERATIONS.md). Short version: tags deploy to production after approval; `migrate:preflight` is available before `migrate --force`; rollback re-deploys the previous tag; database changes are forward-only. After any restore, run `php artisan ops:restore-drill` against the scratch copy and `php artisan evidence:verify` on one pack.

Performance budgets: [PERFORMANCE.md](PERFORMANCE.md).

## 11. Troubleshooting quick hits

| Symptom | Check |
|---|---|
| White page / 500 after upload | `storage/logs/laravel.log`; `.env` present?; PHP version ≥ 8.3 selected in MultiPHP |
| `/install` is 500 on a brand-new unzip | PHP 8.3+ selected for the domain; `storage/` and `bootstrap/cache/` writable; document root is `public/`. A missing `.env` is expected — the wizard creates it. If the log says `MissingAppKeyException` or `database.sqlite does not exist`, the build is older than the first-run bootstrap fix; replace the app files and retry `/install`. |
| Installer stuck on requirements | extension missing (compare §1 list); `storage/` not writable |
| Emails not sending | mail credentials in Settings/.env; queue cron running; test via tinker `Mail::raw()` |
| Styles/JS 404 | `public/build/` missing — deploy the release bundle, not the raw repo |
| Cron "works" but nothing happens | wrong PHP binary path; check the cron's PHP version matches MultiPHP |
| Container is unhealthy | confirm port 8080, `/up`, database reachability, `APP_KEY`, and writable `/var/www/html/storage` volume |
| Installer loops in a container | use `SESSION_DRIVER=file` until migrations exist; confirm the storage volume persists and is writable |

## 12. Runtime security verification

This security patch does not add a migration or a new configuration key. Existing installations can run the new code immediately after replacing application files.

Confirm on the running host:

- [ ] Web-push delivery pins destinations and does not follow redirects. Public endpoints still send; invalid endpoints are still removed.
- [ ] Installer ingress allow-listing is enforced at every public entry point and fails closed. A disallowed source cannot reach any installer step. This is an operational host check, not an application finding closed by this patch.
- [ ] After installation, `storage/app/installed.lock` (or `APP_INSTALLED=true`) makes every `/install` URL unavailable, including `/install/done`.
