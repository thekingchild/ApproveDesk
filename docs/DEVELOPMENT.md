# Development Guide

Local environment setup and day-to-day commands. For repo rules and architecture invariants read [AGENTS.md](../AGENTS.md) first; for pipelines see [CI-CD.md](CI-CD.md).

## Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.3+ (8.3–8.5) | extensions: bcmath, ctype, curl, dom, fileinfo, json, mbstring, openssl, pcre, pdo, tokenizer, xml, zip, gd, **pdo_sqlite** |
| Composer | 2.x | |
| Node.js | 22 LTS (18+ works) | asset builds via Vite |
| Git | any recent | |

Ubuntu/Debian quick install (what Phase 0 runs on this machine):

```bash
sudo apt update
sudo apt install php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl \
                 php8.3-zip php8.3-bcmath php8.3-gd php8.3-mysql unzip
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

MySQL is **not** required locally — dev and tests run on SQLite. It only matters for production parity checks (see "Testing against MySQL" below).

## First-time Setup

```bash
git clone <repo-url> approvedesk && cd approvedesk
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed        # SQLite database + roles + demo data
php artisan storage:link          # public disk symlink (branding also has a route fallback)
npm run dev                       # Vite dev server (keep running)
php artisan serve                 # http://127.0.0.1:8000 (separate terminal)
```

The seeder creates an admin user for local use only — credentials are printed by the seeder and rotate freely (never reused in staging/production; those environments create their first user via the `/install` wizard).

## Daily Commands

```bash
php artisan test                      # full suite (SQLite in-memory)
php artisan test --parallel           # faster — run before pushing
vendor/bin/phpunit --coverage-clover=coverage.xml && php scripts/coverage-gate.php coverage.xml 80   # same gate as CI
./vendor/bin/pint --dirty             # format changed files
php artisan migrate:fresh --seed      # reset dev database
php artisan schedule:work             # run scheduler locally (escalations, digests)
php artisan queue:work                # process queued notifications locally
npm run build                         # production asset build (what CI ships)
```

## Working on the Workflow Engine

- Engine code: `app/Services/WorkflowEngine.php` — start every change by writing the failing topology test in `tests/Feature/Workflow/`.
- Factories for every model exist; combine them to build scenarios (template → steps → instance) rather than creating rows ad hoc in tests.
- Race-condition tests use database transactions with concurrent actor simulation; time-dependent behavior (escalation, delegation expiry) uses `Carbon::setTestNow()`, never `sleep()`.
- `WorkflowEngine` coverage is gated at 97% in CI (measured 97.10%). The AGENTS.md target remains 100%.

## Testing against MySQL (occasional parity check)

SQLite covers the daily loop. GitHub Actions also runs the suite against MySQL 8. You do not need MySQL on the development machine.

To run the same MySQL job locally (optional Docker):

```bash
docker run --name ad-mysql -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=approvedesk_test -p 3306:3306 -d mysql:8.0
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=approvedesk_test \
DB_USERNAME=root DB_PASSWORD=secret php artisan test
```

## Turnstile test keys

Cloudflare publishes dummy keys that never call production. Use them in automated tests with `Http::fake` on `https://challenges.cloudflare.com/turnstile/v0/siteverify`:

- Always-pass site key: `1x00000000000000000000AA`
- Always-pass secret: `1x0000000000000000000000000000000AA`
- Always-block site key: `2x00000000000000000000AB`

Do not put a real secret in `.env` or fixtures. Organization settings store the secret encrypted.

## Conventions Checklist (before opening a PR)

- [ ] Tests added/updated; `php artisan test --parallel` green
- [ ] `./vendor/bin/pint --dirty` clean
- [ ] No business logic in controllers/Livewire components (Actions/Services only)
- [ ] Authorization via Policy classes, not role checks
- [ ] New statuses/types are PHP enums with string columns (no MySQL ENUMs)
- [ ] Eager loading on every relationship used in views (no N+1)
- [ ] Shared-hosting deployments still have no runtime dependency on Redis, Docker, Node, or a long-running worker
- [ ] Migrations are additive (never edit merged migrations)

## Public website

`/` is served from the `website/` directory at the repository root (see `website/README.md`). Put a new static `index.html` there — keep `<nav data-approvedesk-auth></nav>` in the header — and reload. Do not compile the landing page through Vite or copy it into `public/`.
