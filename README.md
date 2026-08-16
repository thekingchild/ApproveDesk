# ApproveDesk

Approval Workflow Application — submit documents, notes, and memos; route them through configurable approval chains (single, sequential, parallel, quorum, conditional, auto-approve, delegation) with an immutable audit trail, notifications, and dashboards. Built on **Laravel 13** (PHP 8.3+) with Livewire 4, Alpine.js, Tailwind CSS and Flux UI, deployable on ordinary **cPanel/Plesk shared hosting** — no Docker, no Redis required.

## Documentation Index

| Document | Purpose |
|---|---|
| [AGENTS.md](AGENTS.md) | Rules and context for AI coding agents & contributors — read first |
| [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) | Phased execution plan with acceptance criteria and milestones |
| [docs/CI-CD.md](docs/CI-CD.md) | Pipelines, branching, releases, deploy automation |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Local setup and daily commands |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Installer runbook, cPanel/Plesk bring-up, hardening |

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

Upload the release bundle, open the domain, and follow the **web installer** at `/install`: server requirements check → database connection → migrations & seed → first admin account → branded login. Panel-specific steps: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Status

Phase 0 (toolchain, scaffold, CI foundation) is the current milestone — see the [implementation plan](IMPLEMENTATION_PLAN.md).
