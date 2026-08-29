# ApproveDesk

Approval Workflow Application — submit documents, notes, and memos; route them through configurable approval chains (single, sequential, parallel, quorum, conditional, auto-approve, delegation) with an immutable audit trail, notifications, and dashboards. **ApproveDesk v2.7.4** is the single-organization product: Laravel 13 (PHP 8.3+), Livewire 4, Alpine.js, Tailwind CSS, and Flux UI. It deploys to ordinary cPanel/Plesk shared hosting without Docker or Redis, and also ships a production container for EasyPanel, Coolify, Docker Compose, and compatible platforms.

## Documentation Index

| Document | Purpose |
|---|---|
| [AGENTS.md](AGENTS.md) | Rules and context for AI coding agents & contributors — read first |
| [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) | Phased execution plan with acceptance criteria and milestones |
| [docs/CI-CD.md](docs/CI-CD.md) | Pipelines, branching, releases, deploy automation |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Local setup and daily commands |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Installer runbook, cPanel/Plesk bring-up, hardening |
| [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md) | Signed email approvals and Google/Microsoft login setup |
| [docs/API.md](docs/API.md) | Personal API tokens, v1 REST endpoints, and signed webhooks |
| [docs/kb/README.md](docs/kb/README.md) | Administrator knowledge base (in-app Help source) |
| [ROADMAP.md](ROADMAP.md) | Candidate work after the single-company release |

## Quick Start (development)

Requires PHP 8.3+, Composer 2, Node 18+ (22 recommended). Full details in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run dev        # terminal 1 — Vite
php artisan serve  # terminal 2 — http://127.0.0.1:8000
```

Dev and daily tests run on SQLite; no MySQL needed locally. GitHub Actions also runs the suite against MySQL 8.

## First Deployment (any new server)

Build a release bundle (`vendor/` and `public/build/` included — the host needs no Node or Composer), upload it, open the domain, and follow the **web installer** at `/install`: server requirements check → database connection → migrations & seed → first admin account → branded login. Platform-specific steps: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

Use **MySQL 8 / MariaDB 10.6+** in production. Host panel backups stay recommended. In-app **Promote** can put a verified database snapshot back on SQLite files and on MySQL.

## Status

**v2.7.4** is the single-company product (phases 0–9 plus installer lock, enforced 2FA, Turnstile, in-app backups, optional second-factor assurance for signed approval links, System owner controls, custom roles, pickable backup times, scheduler diagnostics, named backup snapshots, restore history, application timezone from `APP_TIMEZONE`, OAuth domain matching, owner-only custom code, backup digest checks, public-HTTPS outbound destinations, professional printable approval reports, and shared-host installer plus Cloudflare compatibility fixes). Multi-tenant SaaS is a separate product and is not implemented in this repository.
