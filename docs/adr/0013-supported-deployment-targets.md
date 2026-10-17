# 0013. Shared hosting is the supported deployment target; Docker is optional

Date: 2026-10-17

Status: Accepted (recorded retroactively; shared-hosting target in force since v2.0.0, Phases 0–4; installer lock hardened in Phase IN, v2.5.1; scheduler worker flags revised by RV-OPS-01, Unreleased)

## Context

ApproveDesk is meant for organizations that run ordinary cPanel or Plesk accounts. Those hosts give PHP, a MySQL or MariaDB database, a web root, and one cron entry. They give no Redis, Docker, Supervisor, or long-running processes. Many have no SSH, so first-run setup has to work from a browser. v2.0.0 also shipped a Dockerfile and `compose.yaml` for Docker Compose, EasyPanel, and Coolify. Finding RV-ARC-22 questions whether that path works.

## Decision

- **Primary target: shared hosting (cPanel/Plesk).** PHP 8.3–8.5 (`composer.json` requires `^8.3`, and the installer blocks below 8.3). MySQL 8.0+ or MariaDB 10.6+ in production. SQLite is used for development and tests.
- Queue is `database`, cache is `file`, and sessions are `file`. Redis, Docker, Node at runtime, and long-running workers are never required (AGENTS.md, docs/OPERATIONS.md). Release bundles include `vendor/` and `public/build/`, so the host needs no Composer or Node.
- **One cron entry** runs `php artisan schedule:run` every minute. The schedule starts the queue worker itself (`routes/console.php:27-30`): `queue:work --stop-when-empty --max-time=50 --tries=3 --timeout=90`, `everyMinute()`, `withoutOverlapping(5)`, `runInBackground()`. Each tick drains the database queue and exits. It stops after 50 s so ticks do not stack, and the overlap lock expires after 5 minutes if the host kills the worker. `DB_QUEUE_RETRY_AFTER` defaults to 150 so it stays above `--timeout` (`config/queue.php:45`). `ops:heartbeat` runs each minute, and `ops:scheduler-check` reports a stale heartbeat.
- **First run uses the web installer at `/install`**: requirements, database, migrations and seed, first administrator, finish. `Installer::finalize()` writes `APP_INSTALLED=true` to `.env` and the lock file `storage/app/installed.lock`. After that, `EnsureAppConfigured` returns 404 for every `/install` URL. `Installer::isInstalled()` also accepts `APP_INSTALLED=true` for hosts that cannot write the lock.
- The installer writes `.env` at the base path (`Installer::envPath()`, `config/installer.php`). Its requirements check requires that directory to be writable, together with `storage/framework`, `storage/logs`, and `bootstrap/cache` (`app/Support/Installer.php:112-119`).
- **Docker/Compose is optional and community-supported.** It is not a supported target, and CI does not cover it until CI builds the image and runs an install smoke test. What exists today:
  - The `Dockerfile` builds a PHP 8.4-FPM Alpine image with Nginx and Supervisor. Node is used only in a build stage. The image runs `schedule:work` under Supervisor in place of cron, so the same scheduled worker command drains the queue. It also sets file cache/session and the database queue, and has an `/up` health check.
  - `compose.yaml` adds MariaDB 11.4 and one named volume for `storage/`. The entrypoint creates storage directories, links `public/storage`, and runs `migrate` only when `AUTO_MIGRATE=true`.
  - docs/DEPLOYMENT.md §6–7 documents Compose, EasyPanel, and Coolify, and sends fresh installs to `/install`.
- Nothing in the application depends on Docker. Features must keep working on the shared-hosting profile above.

## Consequences

- Background work runs up to about a minute late, which is acceptable for notifications, escalations, and retention. Scheduled jobs must finish inside the worker's 90 s timeout or continue on the next run.
- The installer needs a writable application directory for the duration of setup. Operators tighten `.env` permissions afterwards (docs/DEPLOYMENT.md §2).
- CI (`.github/workflows/ci.yml`) tests PHP 8.4 only, against SQLite, MySQL 8.0, and MariaDB 10.6. No workflow builds the Docker image (`publish-release.yml` only creates a GitHub release). Docker regressions and PHP 8.3/8.5 differences can therefore ship unnoticed.
- Until the smoke test exists, Docker issues are fixed on a best-effort basis. Docs should not present Compose as on par with cPanel/Plesk.

## Open questions

- **RV-ARC-22 (unconfirmed, needs a run).** In the image, `/var/www/html` is copied as root, and only `storage/` and `bootstrap/cache` are given to `www-data` (`Dockerfile:58-60`). `.dockerignore` excludes `.env`. The installer writes `.env` to the base path, so `/install` probably fails the "application directory (.env creation)" requirement, or fails when `ensureBootstrapEnvironment()` writes the file. Even if that write succeeded, `.env` would live in the container layer and not in the `storage/` volume. This is inferred from the code and not tested.
- docs/DEPLOYMENT.md §2 says the requirements check covers writable `storage/` and `bootstrap/cache/`. The code also requires the `.env` directory to be writable. The doc understates the requirement.
- AGENTS.md and the installer claim PHP 8.3–8.5, but CI runs only 8.4. `^8.3` would also accept a future 8.6. Should CI add a PHP version matrix?
