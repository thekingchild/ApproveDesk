# CI/CD Guide

How changes travel from a developer's machine (or an AI agent) to production on shared hosting. Companion docs: [DEVELOPMENT.md](DEVELOPMENT.md) (local setup), [DEPLOYMENT.md](DEPLOYMENT.md) (server procedures).

Platform: **GitHub Actions**. Target hosts: cPanel (SSH/rsync, FTP fallback) and Plesk (Git pull deployment). Both hosts run the same artifact; first-run setup on any new server is done via the web installer at `/install`.

---

## 1. Branching & Versioning

- **`main`** is always deployable and protected: PRs only, CI green required, no direct pushes.
- Short-lived `feature/<slug>` branches (hours–days, not weeks). Conventional Commits (`feat:`, `fix:`, `chore:` …) — they drive the CHANGELOG.
- **Tags `v*` mark releases** and are the *only* trigger for production deployment.
- Versioning: `vMAJOR.MINOR.PATCH` per the milestone table in [IMPLEMENTATION_PLAN.md](../IMPLEMENTATION_PLAN.md). Every release updates `CHANGELOG.md` before tagging.

## 2. Pipeline Overview

| Workflow | Trigger | What it does |
|---|---|---|
| `ci.yml` — **Quality** | every push + PR | Pint lint → Pest tests (SQLite, parallel, ≥80% coverage) → Vite asset build → upload build artifact |
| `deploy-staging.yml` | push to `main` | Quality (re-run) → build release bundle → rsync/Git-deploy to staging → post-deploy script |
| `deploy-production.yml` | push of `v*` tag | Quality → build release bundle → deploy to production (manual approval gate) → smoke check → tag release on GitHub |

Local equivalent before pushing (this is the CI contract — same commands):

```bash
./vendor/bin/pint --dirty
php artisan test --parallel --coverage --min=80
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
- Tests run against **SQLite in-memory** — fast and MySQL-independent. If a test needs MySQL-only behavior, that test belongs in an explicit opt-in job (`php artisan test --group=mysql`), never the default gate.
- Coverage gate is 80% on `app/`; `WorkflowEngine` is expected at 100% (AGENTS.md invariant).
- `composer audit` fails the build on known vulnerable locked packages.

## 4. Environments & Secrets

GitHub **Environments**: `staging` and `production` (production requires manual approval reviewer).

| Secret | Used by | Notes |
|---|---|---|
| `STAGING_HOST` / `STAGING_USER` / `STAGING_SSH_KEY` | staging deploy | SSH key of a jailed-shell cPanel/Plesk user |
| `PRODUCTION_HOST` / `PRODUCTION_USER` / `PRODUCTION_SSH_KEY` | production deploy | dedicated deploy user, least privilege |
| `DEPLOY_PATH` | both | e.g. `/home/user/approval_workflow` or `/var/www/vhosts/example.com/approvaldesk` |

Never in the repo: `APP_KEY`, DB passwords, mail credentials — those live only in the server's `.env`, created by the installer or admin. CI never needs application secrets.

## 5. Release bundle

Built once in CI (not on the host — shared hosting has no Node):

```yaml
  # inside deploy workflows, after Quality passes
  - name: Build release bundle
    run: |
      composer install --prefer-dist --no-dev --optimize-autoloader --classmap-authoritative --no-interaction
      npm ci && npm run build
      rm -rf node_modules tests .github docs storage/logs/*
      tar -czf release-${GITHUB_SHA}.tar.gz .
```

`storage/` skeleton subdirs are preserved via `.gitkeep`. The bundle keeps `vendor/` and `public/build/` so the host needs neither Composer nor Node for routine deploys.

## 6. Deploy — Plesk (preferred when available)

Plesk deploys via its Git integration; the workflow only needs to notify/pull, or you let Plesk pull `main`/tags on its own webhook:

```yaml
  deploy:
    needs: [php, assets]
    runs-on: ubuntu-latest
    environment: production        # manual approval gate
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - name: Trigger Plesk git deploy
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.PRODUCTION_HOST }}
          username: ${{ secrets.PRODUCTION_USER }}
          key: ${{ secrets.PRODUCTION_SSH_KEY }}
          script: |
            cd ${{ secrets.DEPLOY_PATH }}
            git fetch --tags && git checkout ${{ github.ref_name }}
            bash deploy/post-deploy.sh
```

`deploy/post-deploy.sh` (kept in the repo, executed on the host):

```bash
#!/bin/bash
set -e
cd "$(dirname "$0")/.."

# Vendor & assets arrive via the release bundle/Git; guard if missing:
[ -d vendor ] || composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "Deployed: $(date -u +%FT%TZ)"
```

Maintaining the one-minute cron (`schedule:run`) is documented in [DEPLOYMENT.md](DEPLOYMENT.md) — it is server setup, not per-release.

## 7. Deploy — cPanel (no Plesk Git)

Requires **SSH access** (jailed shell is fine). Pattern: build bundle in CI → `rsync` over SSH → run post-deploy script.

```yaml
      - name: Upload release
        run: |
          rsync -az --delete --exclude '.env' \
            --exclude 'storage/app' --exclude 'storage/logs' \
            --exclude 'storage/framework/sessions' --exclude 'storage/framework/cache' \
            ./ ${{ secrets.PRODUCTION_USER }}@${{ secrets.PRODUCTION_HOST }}:${{ secrets.DEPLOY_PATH }}/
      - name: Finalize on server
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.PRODUCTION_HOST }}
          username: ${{ secrets.PRODUCTION_USER }}
          key: ${{ secrets.PRODUCTION_SSH_KEY }}
          script: |
            cd ${{ secrets.DEPLOY_PATH }}
            bash deploy/post-deploy.sh
```

`--delete` with those excluses keeps user uploads, logs, sessions and the live `.env` intact.

**FTP fallback** (host without SSH): use `SamKirkland/FTP-Deploy-Action@v5` to upload the bundle, then run `post-deploy.sh` once via cPanel Terminal or a one-off cron entry. First-time server setup on either panel still goes through the `/install` wizard — see [DEPLOYMENT.md](DEPLOYMENT.md).

## 8. Post-deploy smoke check

Appended to every deploy job (fail loudly, roll forward by re-deploying previous tag):

```yaml
      - name: Smoke check
        run: curl -fsS https://yourdomain.com/up
```

Laravel's health route `/up` (enable in `bootstrap/app.php`) plus an authenticated probe of `/login` for page-level sanity.

## 9. Rollback

1. In GitHub, re-run the production workflow pinned to the previous tag — or locally: `git tag -f v1.0.1 <previous-sha> && git push origin v1.0.1 -f` (rare; prefer a forward-fix release).
2. Database rollbacks are **forward-only**: write a corrective migration; never `migrate:rollback` in production (audit integrity).
3. Verify `/up`, login, and one approval round-trip after any rollback.

## 10. Definition of done (per release)

- [ ] CI green on the release tag (lint, tests ≥80%, audit, build)
- [ ] CHANGELOG.md entry present
- [ ] Deployed to staging and installer/login/approval smoke-tested there
- [ ] Production deploy approved and smoke check passed
- [ ] Tag pushed; release notes published on GitHub
