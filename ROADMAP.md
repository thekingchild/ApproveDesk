# ApproveDesk Roadmap

This roadmap describes possible work after the **v2.5.4** single-company product. It is planning material only: none of these items is promised, scheduled, or implemented in this repository. Multi-tenant SaaS belongs in a separate product. Priorities should follow customer demand, security review, and shared-hosting compatibility.

## Near-term candidates

- **Enterprise provisioning:** LDAP/Active Directory synchronization and SCIM 2.0 user/group provisioning, with dry-run reconciliation and auditable changes.
- **Additional federation:** configurable SAML 2.0 identity providers and organization-level login policies.
- **Digital signatures:** signer identity evidence, document hashes, signature certificates, and exportable verification records.
- **Localization:** translatable UI, email templates, locale-aware dates, and an initial set of community-reviewed languages.

## Communication and field-work candidates

- **SMS and WhatsApp notifications:** opt-in delivery through pluggable providers such as Africa's Talking and Twilio, with cost limits and delivery receipts.
- **Progressive Web App:** installable shell, resilient mobile approval inbox, and carefully scoped offline draft capture. Approval actions would still require an online server check.
- **Inbound email processing:** secure, provider-verified replies for comments and information requests without weakening the append-only audit model.

## Platform and governance candidates

- **Object-storage lifecycle controls:** retention tiers, legal holds, and storage-provider migration tooling.
- **Advanced compliance exports:** configurable evidence packs, retention policies, and integrity verification for audit archives.
- **Observability integrations:** optional OpenTelemetry traces and external error/uptime providers, without making them runtime requirements.
- **High-availability profile:** documented multi-instance deployment using external sessions/cache and horizontally safe queue processing; the default single-instance/shared-hosting profile remains supported.

## Evaluation gates

A roadmap item moves into implementation only after it has a written threat model, migration/rollback plan, acceptance tests, accessibility impact review, and an explicit decision about shared-hosting support.

