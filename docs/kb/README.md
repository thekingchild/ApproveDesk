# Administrator knowledge base

This folder is the source for ApproveDesk’s in-app Help centre (`/help`). It explains how to use the product. It does not cover installation, hosting, Docker, queues, or environment files. Those stay in `docs/DEPLOYMENT.md` and `docs/DEVELOPMENT.md`.

Audience: organization administrators who already have ApproveDesk running.

## How to keep it current

After each completed phase in `IMPLEMENTATION_PLAN.md`:

1. Update the articles that the phase changed, or add a new article.
2. Register new articles in `App\Support\HelpCatalogue`.
3. Check the Help centre as an administrator and as a requester.
4. Commit the Markdown, catalogue, Help UI, and plan update together.

## Article index (through Phase 6)

Using ApproveDesk:

- [Getting around](getting-around.md)
- [Requests](requests.md)
- [Approvals](approvals.md)
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
- [Audit trail](audit-trail.md)
