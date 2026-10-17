# CI/CD Guide

How changes are checked and how releases are published. Companion docs: [DEVELOPMENT.md](DEVELOPMENT.md) (local setup), [DEPLOYMENT.md](DEPLOYMENT.md) (server procedures).

Platform: **GitHub Actions**. Quality CI runs on every pull request and on `main`. Tags create a GitHub Release. They do **not** deploy application files to staging or production. Operators update self-hosted installations with the updater or the manual procedures in [DEPLOYMENT.md](DEPLOYMENT.md). First-run setup on any new server is done via the web installer at `/install`.

---

## 1. Branching & Versioning

- **`main`** is always deployable: PRs only, CI green required, no direct pushes.
- Short-lived `feature/<slug>` branches (hours–days, not weeks). Conventional Commits (`feat:`, `fix:`, `chore:` …) — they drive the CHANGELOG.
- **Tags `v*` mark releases** and publish a GitHub Release. They do not rsync or SSH to a host.
- Versioning: `vMAJOR.MINOR.PATCH` per the milestone table in [IMPLEMENTATION_PLAN.md](../IMPLEMENTATION_PLAN.md). Every release updates `CHANGELOG.md` before tagging.

## 2. Pipeline Overview

| Workflow | Trigger | What it does |
|---|---|---|
| `ci.yml` — **Quality** | every push to `main` + PR | Pint lint → PHPStan → PHPUnit tests (SQLite, parallel) → coverage gates → PHPUnit on MySQL 8 and MariaDB 10.6 → Vite asset build → upload build artifact |
| `publish-release.yml` | push of `v*` tag | Re-run Quality against the tag → create the GitHub Release notes. No host deployment, SSH, rsync, or environment secrets. |

Local equivalent before pushing (this is the CI contract — same commands):

```bash
./vendor/bin/pint --dirty --test
./vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --parallel
# With PCOV/Xdebug installed:
php -d pcov.enabled=1 -d pcov.directory=app vendor/bin/phpunit --coverage-clover=coverage.xml
php scripts/coverage-gate.php coverage.xml 80
php scripts/coverage-gate.php coverage.xml 97 app/Services/WorkflowEngine.php
npm run build
```

## 3. Quality workflow — `.github/workflows/ci.yml`

The checked-in workflow is the source of truth. Its main jobs:

- **php** — latest PHP 8.4, SQLite in-memory: dependency audit, Pint, PHPStan, parallel tests, 80% `app/` coverage, 97% `WorkflowEngine`.
- **php 8.4.1** — the minimum supported PHP, exactly (official `php:8.4.1-cli` image): parallel tests only.
- **php 8.5** — the newest supported PHP: parallel tests only.
- **mysql** — sequential PHPUnit against MySQL 8.
- **mariadb** — sequential PHPUnit against MariaDB 10.6 (the oldest supported MariaDB).
- **assets** — production Composer install and Vite build; uploads `public/build`.
- **browser** — Dusk browser and axe accessibility tests. Blocks merge. The server runs with `--no-reload` and shares `/tmp/approvedesk-dusk.sqlite` with the test process and `.env.dusk.local`; the server log is printed on failure.

Notes:
- Index and foreign-key names must be 64 characters or fewer (InnoDB). Prefer explicit short names on composite indexes. Do not put unbounded `TEXT`/`BLOB` columns in unique keys (MySQL error 1170); hash long values such as push endpoints first. `Schema::getTableListing()` defaults to schema-qualified names (`approvedesk_test.activity_log`); never wrap that string in one pair of backticks — use `Schema::getTableListing(schemaQualified: false)` or `App\Support\SchemaTable`.
- Coverage gate is 80% on `app/`. `WorkflowEngine` is gated at 97%. The AGENTS.md target remains 100%.
- `composer audit --no-dev --abandoned=ignore` fails the build on known vulnerable locked packages.

## 4. Secrets

CI never needs application secrets (`APP_KEY`, database passwords, mail credentials). Those live only in the server's `.env`, created by the installer or **Settings → System**.

Host SSH keys and GitHub Environments named `staging` / `production` are not used by these workflows.

## 5. Release publication

Pushing an annotated `v*` tag runs Quality, then `gh release create` for that tag. Attach installer artifacts separately if an operator build is required; the workflow does not rsync to a host.

Shared hosting has no Node. Build `vendor/` and `public/build` when preparing a cPanel/Plesk zip as described in [DEPLOYMENT.md](DEPLOYMENT.md).

## 6. Manual deployment

Use [DEPLOYMENT.md](DEPLOYMENT.md) and [`deploy/post-deploy.sh`](../deploy/post-deploy.sh) on the server: maintenance mode, migrate, rebuild caches, restart queue workers, then bring the app back up.

Maintaining the one-minute cron (`schedule:run`) is server setup, not per-release.

**cPanel:** keep the application outside `public_html` and point the domain document root at `public/`.

**Plesk:** set the vhost document root to `approvedesk/public`.

## 7. Post-upgrade smoke check

On the host after a manual upgrade:

```bash
curl -fsS https://yourdomain.com/up
```

Plus an authenticated probe of `/login`.

## 8. Rollback

1. Restore the previous release files from the previous tag or backup zip.
2. Database rollbacks are **forward-only**: write a corrective migration; never `migrate:rollback` in production (audit integrity). `php artisan migrate:preflight` rejects pending drop/rename migrations.
3. Verify `/up`, login, and one approval round-trip after any rollback. Then run `php artisan ops:heartbeat` and confirm **Administration → Operations** shows a fresh scheduler run.
4. Full restore and rollback steps: [OPERATIONS.md](OPERATIONS.md) §3 and §8.

## 9. Definition of done (per release)

- [ ] CI green on the release tag (lint, tests ≥80%, audit, build)
- [ ] CHANGELOG.md entry present
- [ ] Tag pushed; GitHub Release notes published
- [ ] Self-hosted hosts upgraded through the updater or manual deployment and smoke-tested there
