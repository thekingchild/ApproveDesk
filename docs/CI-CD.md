# CI/CD Guide

How changes travel from a developer's machine (or an AI agent) to production on shared hosting. Companion docs: [DEVELOPMENT.md](DEVELOPMENT.md) (local setup), [DEPLOYMENT.md](DEPLOYMENT.md) (server procedures).

Platform: **GitHub Actions**. Target hosts: cPanel (SSH/rsync, FTP fallback), Plesk (Git pull deployment), and Dockerfile-based platforms such as EasyPanel and Coolify. Shared hosts run the same release artifact; container platforms build the checked-in production image. First-run setup on any new server is done via the web installer at `/install`.

---

## 1. Branching & Versioning

- **`main`** is always deployable and protected: PRs only, CI green required, no direct pushes.
- Short-lived `feature/<slug>` branches (hours–days, not weeks). Conventional Commits (`feat:`, `fix:`, `chore:` …) — they drive the CHANGELOG.
- **Tags `v*` mark releases** and are the *only* trigger for production deployment.
- Versioning: `vMAJOR.MINOR.PATCH` per the milestone table in [IMPLEMENTATION_PLAN.md](../IMPLEMENTATION_PLAN.md). Every release updates `CHANGELOG.md` before tagging.

## 2. Pipeline Overview

| Workflow | Trigger | What it does |
|---|---|---|
| `ci.yml` — **Quality** | every push + PR | Pint lint → PHPStan → PHPUnit tests (SQLite, parallel, ≥80% coverage) → PHPUnit on MySQL 8 → Vite asset build → upload build artifact |
| `deploy-staging.yml` | push to `main` | Quality (re-run) → build release bundle → rsync/Git-deploy to staging → post-deploy script |
| `deploy-production.yml` | push of `v*` tag | Quality → build release bundle → deploy to production (manual approval gate) → smoke check → tag release on GitHub |

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

```yaml
name: Quality

on:
  push:
    branches: [main]
  pull_request:

jobs:
  php:
    runs-on: ubuntu-latest
    env:
      DB_CONNECTION: sqlite
      DB_DATABASE: ":memory:"
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: bcmath, ctype, curl, dom, fileinfo, json, mbstring, openssl, pcre, pdo, tokenizer, xml, zip, gd, pdo_sqlite, pcov
          coverage: pcov

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Lint (Pint)
        run: ./vendor/bin/pint --test

      - name: Tests with coverage gate (80%)
        run: php artisan test --parallel --coverage --min=80

      - name: Dependency audit
        run: composer audit --no-dev

  assets:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: "22"
          cache: npm
      - run: npm ci
      - run: npm run build
      - uses: actions/upload-artifact@v4
        with:
          name: frontend-dist
          path: public/build
          retention-days: 7
```

Notes:
- The default Quality suite runs against **SQLite in-memory**. A second sequential MySQL 8 job must stay green. Index and foreign-key names must be 64 characters or fewer (InnoDB). Prefer explicit short names on composite indexes. Do not put unbounded `TEXT`/`BLOB` columns in unique keys (MySQL error 1170); hash long values such as push endpoints first. `Schema::getTableListing()` defaults to schema-qualified names (`approvedesk_test.activity_log`); never wrap that string in one pair of backticks — use `Schema::getTableListing(schemaQualified: false)` or `App\Support\SchemaTable`.
- Coverage gate is 80% on `app/`. `WorkflowEngine` is gated at 97% (measured 97.10% / 11 statements still uncovered). The AGENTS.md target remains 100%.
- `composer audit --no-dev --abandoned=ignore` fails the build on known vulnerable locked packages.

## 4. Environments & Secrets

GitHub **Environments**: `staging` and `production` (production requires manual approval reviewer).

| Secret | Used by | Notes |
|---|---|---|
| `STAGING_HOST` / `STAGING_USER` / `STAGING_SSH_KEY` / `STAGING_KNOWN_HOSTS` | staging deploy | SSH key plus a trusted `known_hosts` entry for the jailed-shell user |
| `PRODUCTION_HOST` / `PRODUCTION_USER` / `PRODUCTION_SSH_KEY` / `PRODUCTION_KNOWN_HOSTS` | production deploy | dedicated deploy user, least privilege, with pinned host identity |
| `DEPLOY_PATH` | both | e.g. `/home/user/approval_workflow` or `/var/www/vhosts/example.com/approvaldesk` |

Set an `APP_URL` environment variable in each GitHub Environment for its post-deploy `/up` smoke check.

Never in the repo: `APP_KEY`, DB passwords, mail credentials — those live only in the server's `.env`, created by the installer or admin. CI never needs application secrets.

## 5. Release build

Built in the deployment workflow (not on the host — shared hosting has no Node):

```yaml
  # inside deploy workflows, after Quality passes
  - name: Build release
    run: |
      composer install --prefer-dist --no-dev --optimize-autoloader --classmap-authoritative --no-interaction
      npm ci && npm run build
```

The deployment's `rsync` excludes development files and persistent server paths. It transfers `vendor/` and `public/build/`, so the host needs neither Composer nor Node for routine deploys.

## 6. Automated SSH deployment

The checked-in staging and production workflows use the same host-neutral sequence for cPanel or Plesk:

1. Re-run the reusable Quality workflow against the exact deployment ref.
2. Install production PHP dependencies and build frontend assets on GitHub's runner.
3. Verify the SSH host against the environment's pinned `known_hosts` secret.
4. `rsync --delete` the release while preserving `.env`, uploads, logs, caches, and sessions.
5. Execute the checked-in [`deploy/post-deploy.sh`](../deploy/post-deploy.sh), which enters maintenance mode, migrates, rebuilds caches, restarts queue workers, and always brings the app back up.
6. Probe the environment's `APP_URL/up` endpoint with retries.

Maintaining the one-minute cron (`schedule:run`) is documented in [DEPLOYMENT.md](DEPLOYMENT.md) — it is server setup, not per-release.

## 7. Panel-specific notes

**cPanel:** SSH access is required for automation (jailed shell is fine). Keep the application outside `public_html` and point the domain document root/front controller at its `public/` directory as described in [DEPLOYMENT.md](DEPLOYMENT.md).

**Plesk:** set the vhost document root to `approvedesk/public`. Plesk Git deployment remains an optional alternative, but the provided GitHub workflow uses SSH/rsync consistently across both panel types.

**FTP fallback** (host without SSH): use `SamKirkland/FTP-Deploy-Action@v5` to upload the bundle, then run `post-deploy.sh` once via cPanel Terminal or a one-off cron entry. First-time server setup on either panel still goes through the `/install` wizard — see [DEPLOYMENT.md](DEPLOYMENT.md).

## 8. Post-deploy smoke check

Appended to every deploy job (fail loudly, roll forward by re-deploying previous tag):

```yaml
      - name: Smoke check
        run: curl -fsS https://yourdomain.com/up
```

Laravel's health route `/up` (enable in `bootstrap/app.php`) plus an authenticated probe of `/login` for page-level sanity.

## 9. Rollback

1. Run **Deploy Production** manually and provide the previous immutable release tag as `release_ref`.
2. Database rollbacks are **forward-only**: write a corrective migration; never `migrate:rollback` in production (audit integrity). `php artisan migrate:preflight` rejects pending drop/rename migrations.
3. Verify `/up`, login, and one approval round-trip after any rollback. Then run `php artisan ops:heartbeat` and confirm **Administration → Operations** shows a fresh scheduler run.
4. Full restore and rollback steps: [OPERATIONS.md](OPERATIONS.md) §3 and §8.

## 10. Definition of done (per release)

- [ ] CI green on the release tag (lint, tests ≥80%, audit, build)
- [ ] CHANGELOG.md entry present
- [ ] Deployed to staging and installer/login/approval smoke-tested there
- [ ] Production deploy approved and smoke check passed
- [ ] Tag pushed; release notes published on GitHub
