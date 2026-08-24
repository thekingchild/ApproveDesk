# Deployment Guide — Shared Hosting and Containers

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
4. **Finish** — lock file `storage/app/installed.lock` is written; `/install` 404s from then on. Redirect to the branded login page.

Security notes for operators:
- The `.env` must be web-writable during install — after finishing, tighten: `chmod 644 .env` (or 400).
- The installer middleware also honors `APP_INSTALLED=true` for environments where the lock file can't be written.
- Re-running setup requires deliberately deleting the lock file (documented recovery path for broken installs only).

## 3. cPanel Bring-Up (once per server)

**Database:** cPanel → MySQL Databases → create DB + user, grant privileges. Restrict later per §6.

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

Find the right PHP binary via MultiPHP Manager (e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`). The scheduler itself launches the queue worker on shared hosting (`routes/console.php`):

```php
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=90')
    ->everyMinute()->withoutOverlapping();
```

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

## 5. Docker Compose

The production image contains PHP-FPM, Nginx, and the Laravel scheduler under Supervisor. The scheduled database queue worker still drains and exits once per minute, so Redis is not required. Application uploads, installer state, sessions, and logs live in the persistent `approvedesk-storage` volume.

1. Copy the repository and create a deployment environment file (do not commit it):
   ```bash
   cp .env.example .env.docker
   ```
2. At minimum, set `APP_URL`, a production `APP_KEY`, `DB_PASSWORD`, and `DB_ROOT_PASSWORD`. Generate a key with `php artisan key:generate --show` on any trusted checkout.
3. Start the stack:
   ```bash
   docker compose --env-file .env.docker up -d --build
   ```
4. Route HTTPS traffic to port 8080, visit `/install`, and complete the first-admin flow. The Compose database values are already available to the app; use host `database` and port `3306` if the installer asks.

The image exposes `/up` as its health check. Set `AUTO_MIGRATE=true` only for an established installation whose deployment process has already handled backups and migration review. Fresh installations should use `/install` so roles, settings, the first administrator, and the installation lock are created together.

Useful operations:

```bash
docker compose logs -f app
docker compose exec app php artisan about
docker compose exec app php artisan queue:failed
docker compose pull && docker compose up -d --build
```

Back up both named volumes. Never recreate or remove them during a routine release.

## 6. EasyPanel and Coolify

Both platforms can build the checked-in `Dockerfile` directly.

1. Create a MariaDB/MySQL service and an application service from this Git repository using the Dockerfile build type.
2. Expose container port `8080`; configure the health path as `/up`.
3. Attach a persistent volume at `/var/www/html/storage`.
4. Provide `APP_KEY`, `APP_URL`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` as platform secrets. Use `CACHE_STORE=file`, `QUEUE_CONNECTION=database`, and `SESSION_DRIVER=file`. Do not add Redis.
5. Add SMTP and optional Google/Microsoft variables from [AUTHENTICATION.md](AUTHENTICATION.md).
6. Deploy, confirm `/up`, then visit `/install`. When the database is a separately managed service, enter that service's internal hostname rather than `database`.

Do not enable multiple app replicas with the local file/session volume. A future high-availability profile would require shared object storage and shared session/cache services; it is roadmap work, not part of v2.0.0.

## 7. Post-install checklist (per server, once)

- [ ] `/up` health route returns 200 over HTTPS
- [ ] Cron firing (check `storage/logs/schedule.log` or scheduler output)
- [ ] Queued emails delivering (send a test approval round-trip)
- [ ] Uploads working and served via signed URLs (not direct paths)
- [ ] `php artisan storage:link` run if using the public disk
- [ ] Installer locked (`/install` → 404)
- [ ] `.env` permissions tightened; `APP_DEBUG=false`
- [ ] Backups scheduled (JetBackup / Plesk Backup Manager) **and a restore drill done**
- [ ] Admin accounts have 2FA enabled

## 8. Hardening the database user (audit integrity)

After installation, reduce the MySQL user's privileges:

```sql
-- Application user: no DDL, and append-only on the audit table
REVOKE ALL PRIVILEGES ON approvedesk.* FROM 'app_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON approvedesk.* TO 'app_user'@'localhost';
REVOKE UPDATE, DELETE ON approvedesk.workflow_actions FROM 'app_user'@'localhost';
FLUSH PRIVILEGES;
```

This makes the immutability of `workflow_actions` a database-level guarantee, per the compliance requirement in the spec. Use a separate read-only user for reporting exports if Phase 3 analytics get heavy.

## 9. Routine releases & rollback

Handled by CI — see [CI-CD.md §6–9](CI-CD.md) and the full runbook in [OPERATIONS.md](OPERATIONS.md). Short version: tags deploy to production after approval; `migrate:preflight` is available before `migrate --force`; rollback re-deploys the previous tag; database changes are forward-only. After any restore, run `php artisan ops:restore-drill` against the scratch copy and `php artisan evidence:verify` on one pack.

Performance budgets: [PERFORMANCE.md](PERFORMANCE.md).

## 10. Troubleshooting quick hits

| Symptom | Check |
|---|---|
| White page / 500 after upload | `storage/logs/laravel.log`; `.env` present?; PHP version ≥ 8.3 selected in MultiPHP |
| Installer stuck on requirements | extension missing (compare §1 list); `storage/` not writable |
| Emails not sending | mail credentials in Settings/.env; queue cron running; test via tinker `Mail::raw()` |
| Styles/JS 404 | `public/build/` missing — deploy the release bundle, not the raw repo |
| Cron "works" but nothing happens | wrong PHP binary path; check the cron's PHP version matches MultiPHP |
| Container is unhealthy | confirm port 8080, `/up`, database reachability, `APP_KEY`, and writable `/var/www/html/storage` volume |
| Installer loops in a container | use `SESSION_DRIVER=file` until migrations exist; confirm the storage volume persists and is writable |
