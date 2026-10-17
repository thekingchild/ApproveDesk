# 0013. Shared hosting is the supported deployment target; Docker is not supported

Date: 2026-10-17

Status: Accepted (recorded retroactively; shared-hosting target in force since v2.0.0, Phases 0–4; installer lock hardened in Phase IN, v2.5.1; scheduler worker flags revised by RV-OPS-01, Unreleased; amended 2026-10-17: Docker is not supported)

## Context

ApproveDesk is meant for organizations that run ordinary cPanel or Plesk accounts. Those hosts give PHP, a MySQL or MariaDB database, a web root, and one cron entry. They give no Redis, Docker, Supervisor, or long-running processes. Many have no SSH, so first-run setup has to work from a browser. v2.0.0 also shipped a Dockerfile and `compose.yaml` for Docker Compose, EasyPanel, and Coolify. Finding RV-ARC-22 questions whether that path works.

## Decision

- **Primary target: shared hosting (cPanel/Plesk).** PHP 8.4.1–8.5 (`composer.json` requires `^8.4.1`, and the installer blocks below 8.4.1). Raised from 8.3 on 2026-10-17 (RV-TEST-06): the locked dependencies (Symfony 8.1, spatie/laravel-activitylog 5) need PHP 8.4.1, so 8.3 was never actually runnable. MySQL 8.0+ or MariaDB 10.6+ in production. SQLite is used for development and tests.
- Queue is `database`, cache is `file`, and sessions are `file`. Redis, Docker, Node at runtime, and long-running workers are never required (AGENTS.md, docs/OPERATIONS.md). Release bundles include `vendor/` and `public/build/`, so the host needs no Composer or Node.
- **One cron entry** runs `php artisan schedule:run` every minute. The schedule starts the queue worker itself (`routes/console.php:27-30`): `queue:work --stop-when-empty --max-time=50 --tries=3 --timeout=90`, `everyMinute()`, `withoutOverlapping(5)`, `runInBackground()`. Each tick drains the database queue and exits. It stops after 50 s so ticks do not stack, and the overlap lock expires after 5 minutes if the host kills the worker. `DB_QUEUE_RETRY_AFTER` defaults to 150 so it stays above `--timeout` (`config/queue.php:45`). `ops:heartbeat` runs each minute, and `ops:scheduler-check` reports a stale heartbeat.
- **First run uses the web installer at `/install`**: requirements, database, migrations and seed, first administrator, finish. `Installer::finalize()` writes `APP_INSTALLED=true` to `.env` and the lock file `storage/app/installed.lock`. After that, `EnsureAppConfigured` returns 404 for every `/install` URL. `Installer::isInstalled()` also accepts `APP_INSTALLED=true` for hosts that cannot write the lock.
- The installer writes `.env` at the base path (`Installer::envPath()`, `config/installer.php`). Its requirements check requires that directory to be writable, together with `storage/framework`, `storage/logs`, and `bootstrap/cache` (`app/Services/Install/Installer.php:112-119`).
- **Docker and container platforms are not supported** (amended 2026-10-17, RV-ARC-22). The project owner decided Docker is not needed for now. The `Dockerfile`, `compose.yaml`, and `docker/` files remain in the repository from v2.0.0, but CI does not build them, docs/DEPLOYMENT.md §6 says not to use them, and problems reported on them are not fixed. What they contain, for anyone who revives them:
  - The `Dockerfile` builds a PHP 8.4-FPM Alpine image with Nginx and Supervisor. Node is used only in a build stage. Supervisor runs `schedule:work` in place of cron. The image sets file cache/session and the database queue, and has an `/up` health check.
  - `compose.yaml` adds MariaDB 11.4 and one named volume for `storage/`. The entrypoint creates storage directories, links `public/storage`, and runs `migrate` only when `AUTO_MIGRATE=true`.
- Nothing in the application depends on Docker. Features must keep working on the shared-hosting profile above.

## Consequences

- Background work runs up to about a minute late, which is acceptable for notifications, escalations, and retention. Scheduled jobs must finish inside the worker's 90 s timeout or continue on the next run.
- The installer needs a writable application directory for the duration of setup. Operators tighten `.env` permissions afterwards (docs/DEPLOYMENT.md §2).
- CI (`.github/workflows/ci.yml`) tests the latest PHP 8.4 against SQLite, MySQL 8.0, and MariaDB 10.6, the minimum PHP 8.4.1 and the newest PHP 8.5 against SQLite. No workflow builds the Docker image (`publish-release.yml` only creates a GitHub release).
- Docker issues are not fixed. Supporting containers again would need a new decision, an image that lets the installer write `.env` to a persistent volume, and a CI job that builds the image and smoke-tests `/install`.

## Open questions

- **RV-ARC-22** is closed by this amendment rather than fixed: the image probably cannot pass the installer's `.env` check (only `storage/` and `bootstrap/cache` are writable by `www-data`, `Dockerfile:58-60`). It was never confirmed by a run.
- docs/DEPLOYMENT.md §2 says the requirements check covers writable `storage/` and `bootstrap/cache/`. The code also requires the `.env` directory to be writable. The doc understates the requirement.
- `^8.4.1` would also accept a future PHP 8.6; CI only proves 8.4.1–8.5. Revisit when 8.6 is released.
