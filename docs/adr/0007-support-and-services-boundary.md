# 0007. What belongs in `app/Support`, `app/Services`, and `app/Contracts`

Date: 2026-10-17

Status: Proposed

## Context

AGENTS.md defines `Actions`, `DTOs`, `Services`, and `Events/Listeners/Jobs`. It says nothing about `app/Support`, which now holds 39 classes, or about `app/Contracts`. The Wave 3 review (RV-ARC-12) found that the two folders overlap:

- Some Support classes do domain work or I/O:
  - `Installer` writes `.env`, purges the database and assigns the owner role.
  - `Turnstile` stores secrets and makes HTTP calls.
  - `SeparationOfDuties` is a core engine rule.
  - `ScheduledBackupHealth::record()` writes data.
  - `SecondFactorAssurance` holds session state.
  - `PublicWebsite` builds HTTP responses.
  - `StepConditionForm` is Livewire form state.
  - `SystemSettings::put()`/`putSecret()` write from inside a reader.
- Some Services are pure functions: `RichTextSanitizer`, `SqlStatementStream`, `UserImportReadFilter` and `WorkflowTemplateSchemaHasher`. `ChatActionToken` signs links the same way `Support/EmailApprovalLinks` does.
- Two interfaces sit outside `app/Contracts`: `Support/DnsResolver` and `Services/Backups/BackupTransport`.
- Typed settings readers exist (`SystemSettings`, `BackupSettings`, `SecuritySettings`, `RegistrationSettings`, `Branding`), but 45 raw `Setting::get('key', 'default')` calls in 17 files repeat keys and defaults. One example is retention, which is repeated between `ApplyRetentionJob` and the Records page.

Without a rule, new classes land wherever the author last looked, and reviews can't tell a misplaced rule from a helper.

## Decision

**`app/Support`: stateless helpers and value readers.**
- Formatting and parsing, such as `LocalTime`, `E164Phone` and `MarkdownText`.
- Value objects and enums-with-logic that hold no services.
- Typed **readers** for settings (one class per settings group, read-only) and catalogues such as `PermissionCatalogue` and `HelpCatalogue`.
- A Support class may read config and settings. It does not write to the database, filesystem, session or network, and it does not decide workflow or authorization outcomes.

**`app/Services`: domain behaviour and anything with side effects.**
- Workflow rules, including `SeparationOfDuties`, which moves next to the engine under `Services/Workflow`.
- I/O: files, HTTP, mail, backups. Installer steps. Session-backed assurance state.
- Integrations.
- Services may be stateful per request and are resolved from the container.

**`app/Actions`: one use case each.** They orchestrate Services. Settings **writes** happen in Actions (or a Service an Action calls), never in a reader.

**`app/Contracts`: every interface the container binds.** `DnsResolver` and `BackupTransport` move here.

**Settings keys and defaults live in exactly one reader per group.** New code never calls `Setting::get` outside a reader. Existing calls move when their area is next touched. Retention is first, since RV-ARC-14 needs a `RetentionSettings` reader anyway.

**How the moves happen**
- Namespace-only moves, one area per PR, with no behaviour change, so the diff is reviewable.
- **Pinned, never moved:** `App\Support\SchemaTimestamps` and `App\Support\PermissionCatalogue` (referenced by released migrations), and `App\Services\RequestTypes\BackfillRequestTypeWorkflowAssignments` and `App\Services\WorkflowTemplates\BackfillWorkflowTemplateVersions` (likewise).
- `HelpCatalogue` stays where AGENTS.md names it.

## Consequences

- **Move list** (each is a separate follow-up, in this order):
  1. `SeparationOfDuties` → `Services/Workflow`.
  2. `DnsResolver` and `BackupTransport` → `Contracts`.
  3. `Turnstile`, `SecondFactorAssurance` → `Services/Auth`.
  4. `ScheduledBackupHealth` → `Services/Backups`.
  5. `Installer` → `Services/Install`.
  6. `PublicWebsite` → `Http`.
  7. `StepConditionForm` → `app/Livewire/Forms`.
  8. The four pure helpers → `Support`.
  9. `SystemSettings` writes → an Action.
- Reviewers can reject a Support class that writes or decides, without debate.
- Some imports churn for a while. PHPStan and the test suite catch missed references, and no public URL or stored value changes.
