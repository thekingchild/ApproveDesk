# ApproveDesk

Approval Workflow Application — submit documents, notes, and memos; route them through configurable approval chains (single, sequential, parallel, quorum, conditional, auto-approve, delegation) with an immutable audit trail, notifications, and dashboards. **ApproveDesk v2.0.0** is built on Laravel 13 (PHP 8.3+) with Livewire 4, Alpine.js, Tailwind CSS and Flux UI. It deploys to ordinary cPanel/Plesk shared hosting without Docker or Redis, and now also ships a production container for EasyPanel, Coolify, Docker Compose, and compatible platforms.

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
| [ROADMAP.md](ROADMAP.md) | Candidate post-v2 work (planning only) |

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

Dev and tests run on SQLite; no MySQL needed locally.

## First Deployment (any new server)

Upload the release bundle or deploy the container image, open the domain, and follow the **web installer** at `/install`: server requirements check → database connection → migrations & seed → first admin account → branded login. Platform-specific steps: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Status

Phases 0–4 are implemented as v2.0.0. Future candidates are documented separately in the [roadmap](ROADMAP.md) and are not implemented.
