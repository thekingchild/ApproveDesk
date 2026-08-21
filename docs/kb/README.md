# Administrator knowledge base

This folder is the source for ApproveDesk’s in-app Help centre (`/help`). It explains how to use the product. It does not cover installation, hosting, Docker, queues, or environment files. Those stay in `docs/DEPLOYMENT.md` and `docs/DEVELOPMENT.md`.

Audience: organization administrators who already have ApproveDesk running, and other signed-in roles for the non-admin articles.

A phase in `IMPLEMENTATION_PLAN.md` is not complete until this knowledge base is updated.

## What every article must contain

- A definition of the feature in plain language
- When to use it
- How to use the live screens
- What not to do, including anything that is immutable

Write for the screens people click. Do not describe installer, server, Docker, CI, or `.env` setup.

## How to keep it current

After each completed phase in `IMPLEMENTATION_PLAN.md`:

1. Update the articles that the phase changed, or add a new article.
2. Add or refresh definitions in [definitions.md](definitions.md) for any new product term.
3. Register new articles in `App\Support\HelpCatalogue`.
4. Check the Help centre as an administrator and as a requester.
5. Commit the Markdown, catalogue, Help UI, and plan update together.

## Article index (through P8-NOT-01)

Using ApproveDesk:

- [Feature definitions](definitions.md)
- [Getting around](getting-around.md)
- [Requests](requests.md)
- [Approvals](approvals.md)
- [Collaboration](collaboration.md)
- [Request statuses](statuses.md)
- [Your account](account-settings.md)

Administration:

- [Users and roles](users-and-roles.md)
- [Departments](departments.md)
- [Workflow templates](workflow-templates.md)
- [Request types](request-types.md)
- [Analytics and export](analytics.md)
- [Webhooks](webhooks.md)
- [Organization settings](organization-settings.md)
- [Notification channels](notification-channels.md)
- [Audit trail](audit-trail.md)
