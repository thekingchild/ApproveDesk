# 0003. ApproveDesk SaaS uses shared-database organization tenancy

Date: 2026-08-25

Status: Accepted

## Context

ApproveDesk v2.5.4 is a supported single-company product. Phase 10 begins the
ApproveDesk SaaS conversion while preserving that self-hosted product. The SaaS
must isolate organizations without weakening the append-only workflow history,
transactional workflow engine, shared-hosting compatibility, or existing user
credentials.

Tenancy affects more than HTTP queries. Web and API requests, policies, queue
jobs, notifications, commands, scheduled tasks, exports, files, cache entries,
locks, webhooks, API tokens, search, and audit records all need one authoritative
organization boundary. Platform operations also need to remain separate from an
organization's administration.

This ADR records the architecture boundary. P10-ADR-02 implements application
modes, and P10-ADR-03 implements explicit organization context and fail-closed
behavior.

## Decision

### Tenancy and identity model

- ApproveDesk SaaS uses one application deployment and one shared database.
- `organizations` are the tenant boundary. Every tenant-owned record will carry
  an `organization_id` through additive Phase 10 migrations.
- A user has one global login identity and may belong to multiple organizations
  through `organization_memberships`. The `users` table will not gain a single
  authoritative `organization_id`.
- Existing installations become one deterministic default organization during
  the Phase 10 backfill. User credentials, workflow instances, workflow actions,
  references, timestamps, and audit history must not be rewritten merely to
  create that organization.
- Tenant conversion follows expand, backfill, validate, constrain, then contract.
  New organization foreign keys begin nullable; non-null constraints arrive only
  after the validation command proves the backfill complete.

### Tenant resolution

- Organization context is explicit and request-scoped. Tenant-owned data access
  must not infer an organization from the first membership, a role name, or an
  untrusted request value.
- Authenticated web and API routes use an organization slug in the canonical URL.
  The initial canonical shape is `/o/{organization:slug}/...` for web routes and
  `/api/v1/o/{organization:slug}/...` for API routes.
- The authenticated user must have an active membership in the resolved
  organization before tenant routes proceed. A valid slug alone grants nothing.
- Legacy unscoped URLs may redirect to the user's saved default organization
  during the P10-TEN-03 deprecation window. Ambiguous users are sent to an
  organization chooser; the application never silently selects an arbitrary
  membership.
- Queue jobs, notifications, commands, scheduled tasks, exports, webhook work,
  and other non-HTTP entry points must receive organization identity explicitly;
  there is no URL-derived fallback outside an HTTP request.

### Domain strategy

- The slug path is the canonical and universal tenant address. It works on local,
  shared-hosted, staging, and SaaS deployments without wildcard DNS.
- `organization_domains` may map verified custom hostnames to organizations.
  Host-based resolution is accepted only after exact normalized-host lookup and
  domain verification. Request headers cannot override a resolved organization.
- A custom domain changes presentation and resolution, not ownership. Generated
  signed URLs and callbacks must bind to the same organization and reject a host,
  slug, or organization mismatch.
- Subdomain-only tenancy and wildcard DNS are not prerequisites for Phase 10.

### Platform and organization authorization

- Platform roles and organization roles are separate authorization domains.
- Organization permissions are evaluated against the active organization and an
  active membership. The implementation may use Spatie's team capability if its
  schema and cache behavior satisfy the isolation tests in P10-DATA-03.
- Platform operators may manage platform-level concerns, but a platform role does
  not grant tenant record access or impersonation.
- Any future support access requires an explicit, time-bounded, audited grant as
  defined by P12-OPS-02. There is no hidden platform-superuser bypass.
- The current self-hosted `super-admin` role is organization-local after
  conversion; it is not automatically a SaaS platform role.

### Self-hosted compatibility

- The same codebase supports `self_hosted` and `saas` application modes. The
  concrete `APP_MODE` configuration and validation belong to P10-ADR-02.
- Self-hosted mode uses one default organization and preserves today's ordinary
  shared-hosting requirements: MySQL/MariaDB, database queues, file cache, one
  scheduler cron, and no required Redis, wildcard DNS, Docker, or long-running
  runtime process.
- Billing, public SaaS onboarding, and platform administration remain unavailable
  in self-hosted mode.
- Self-hosted compatibility does not permit unscoped tenant-owned queries. Code
  paths use the same organization boundary so behavior cannot diverge into an
  unsafe SaaS-only implementation.

## Rejected alternatives

### Database per organization

Rejected for Phase 10 because it multiplies migrations, connection management,
backups, queue context, reporting, and shared-hosting operations. It also makes a
safe conversion of the existing installation materially harder. A future
enterprise deployment may use a different physical isolation adapter without
changing the organization ownership model.

### Schema or table prefix per organization

Rejected because MySQL/MariaDB operational complexity grows with every tenant,
while Laravel migrations and shared-hosting tooling assume one schema.

### One organization directly on each user

Rejected because it prevents a person from belonging to several organizations,
complicates invitations and switching, and encourages authorization based on a
user attribute instead of active context and membership.

### Subdomain-only resolution

Rejected because wildcard DNS and certificates are not consistently available on
supported shared hosts. Canonical slug paths remain valid everywhere; verified
custom domains are additive.

### Platform super-admin automatically bypasses tenant policies

Rejected because it creates an invisible cross-tenant access path and contradicts
the Phase 9 tenancy threat model. Support access must be explicit and audited.

## Consequences

- Tenant-owned tables and all side-effect boundaries require an organization
  inventory and isolation tests before Phase 10 can complete.
- Identical numeric IDs and reference numbers may exist in different
  organizations; uniqueness and cache/storage keys must include organization.
- Route generation, signed URLs, files, exports, queues, notifications, webhooks,
  API tokens, search, and locks must preserve organization identity end to end.
- Existing unscoped code cannot be made safe solely by adding an Eloquent global
  scope. Policies, explicit context, database constraints, and cross-tenant tests
  remain required.
- P10-ADR-02 and P10-ADR-03 must implement and test the mode and context contracts
  before tenant-owned schema conversion begins.
- This decision changes no schema, runtime behavior, UI, or Help centre content by
  itself.
