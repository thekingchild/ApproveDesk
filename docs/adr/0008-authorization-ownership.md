# 0008. Who authorizes what: entry points, Actions, and the workflow engine

Date: 2026-10-17

Status: Accepted (2026-10-17)

## Context

AGENTS.md requires a Policy on every controller and Livewire action, and forbids role-name checks in controllers. It does not say whether the entry point or the Action is responsible, so the code does both, inconsistently:

- **Entry points.**
  - Routes use `can:` middleware.
  - Pages call `$this->authorize()` 111 times, but some mutating methods rely on the Action instead. On `submissions/show`, `act`, `delegateApproval`, the follow methods and `sendNudge` don't authorize themselves (RV-ARC-03).
  - Controllers mix Form Request `authorize()`, `Gate::authorize` (8 sites) and `abort_unless(...->can())` (2 sites).
- **Actions.** 16 Action files call `Gate::`, among them `PostCommentAction`, `FollowSubmissionAction`, `SaveAnnouncementAction` and `SaveThemeAction`. Others trust their caller. `RestoreWorkflowTemplateVersionAction` checked nothing, and its page authorized only at mount (RV-ARC-07).
- **Step eligibility.** "May this user act on this step" was re-implemented about six times and the copies disagreed (RV-ARC-01). Since #63 the request page and the workbench ask `WorkflowEngine::canAct()`. `SubmissionPolicy::approveViaEmail` and two SQL constraints still keep their own copies.
- **Role names.** Checks remain outside Policies (RV-ARC-20): `ReportService` picks the dashboard mode with `hasAnyRole(['super-admin','admin'])`, and `User::isReadOnlyAuditor` uses `hasRole('auditor')`. System-owner gates are written inside components (`settings/approvals.blade.php:53,98,212`). There are no role constants.
- **Owner abilities.** `Gate::before` already grants catalogued permissions through `FeaturePermission` and gives the System owner every ability (`AppServiceProvider.php:137`).

The cost is that a rule can be enforced on the web screen but not on the API, chat, email or a queued job. The opposite also happens, where an Action enforces a rule the screen doesn't know about, so the screen offers buttons that then fail.

## Decision

**1. Entry points authorize the ability, always.**
- Route middleware is not enough on its own.
- Livewire: every public method that reads protected data or changes anything calls `$this->authorize()` first (ADR 0006).
- Controllers: use the Form Request's `authorize()`, or `Gate::authorize()`. No `abort_unless(...->can())`.
- The ability the entry point checks is the same one the template uses to show or hide the control, so a visible button means the request will be accepted.

**2. Actions enforce their own invariants** when either of these applies:
- **(a)** the Action is reachable from more than one entry point: web, API, chat, email, scheduled job or import;
- **(b)** the rule is a security invariant: owner-only operations, the permission ceiling (ADR 0009), separation of duties, four-eyes on dispositions, and legal hold.

They do this with `Gate::forUser($actor)->authorize(...)` and throw `AuthorizationException`. Single-screen Actions with no invariant may rely on their entry point; the Action's docblock says so.

**3. The workflow engine is the only authority on step eligibility.**
- `WorkflowEngine::canAct()`/`canDelegate()` (read-only) and `processAction()`/`delegate()` (enforcing) share one private implementation.
- Policies, screens, lists and channels call the engine and never re-derive assignment, delegation or "already decided".
- Where a list needs the same answer in bulk (inbox, reports), the in-memory or SQL version lives next to the engine. A parity test runs both against the same fixtures.

**4. Policies and Gates are the only place that names roles.**
- Protected tiers (`super-admin`, `admin`, `auditor`) come from a `SystemRole` enum.
- Non-model abilities are named Gates, for example `dashboard.admin`, `approval_reference.manage` and `system_owner`, so components call `$this->authorize('approval_reference.manage')` instead of `isSystemOwner()`.
- The RBAC implementation itself (`RoleAssignmentService`, `GrantableRoleService`, `RolePermissionService`) may read roles. It is the mechanism, not a caller.

## Consequences

- **Follow-up PRs**, in order:
  1. `SubmissionPolicy::approveViaEmail` → engine. This turns a stale email link into a clear refusal page rather than a confusing error, and needs its own UX copy.
  2. Add `authorize()` to the `submissions/show` mutators that lack it.
  3. Authorize inside `RestoreWorkflowTemplateVersionAction`.
  4. `SystemRole` enum and named Gates; remove `hasRole` and `hasAnyRole` from services, models and views.
  5. Replace `abort_unless(...->can())` in controllers.
- Some checks run twice: at the entry point and in the Action. That is deliberate for the cases in (2) and cheap, because Policy checks are in-memory once permissions are cached.
- The authorization-matrix tests planned in REVIEW_PLAN §6.8 test the abilities named in (1) and (4), rather than role names.
