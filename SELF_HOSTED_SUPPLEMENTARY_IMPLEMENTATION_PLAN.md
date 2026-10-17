# Self-Hosted Owner, Account Recovery, and Active-User Cap Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Give the first user created by the single-company ApproveDesk installer exclusive owner privileges to protect the owner account, delegate existing roles, recover other users’ passwords and second factors, and set a concurrency-safe total active-user limit.

**Architecture:** Add an explicit singleton system-owner record instead of inferring ownership from a role or user ID at runtime. Keep the internal `super-admin` role for compatibility, but reserve it for the singleton owner; all other administration is delegated through existing non-owner roles. Add owner-only recovery actions with recent reauthentication, durable security-event records, notification, and session/token revocation. Add a singleton site-limit record and one transaction/row-lock service used by every production account-activation path. Do not use a global Eloquent scope for owner invisibility, because it would corrupt relationship loading, historical attribution, evidence, and workflow behavior.

**Tech Stack:** Laravel 13, PHP 8.4.1+, Livewire 4 anonymous page components, Flux UI, Fortify 2FA/passkeys/password broker, Sanctum, Spatie Permission, SQLite tests, MySQL/MariaDB production.

**Roadmap boundary:** This plan is only for the **single-company, self-hosted ApproveDesk line based on v2.5.5**. It must not implement or modify ApproveDesk SaaS tenancy, billing, platform-console, organization, or entitlement roadmap items. ApproveDesk SaaS must use a separate repository or a `saas`-named branch and maintain its own roadmap. Do not reuse Phase 10–15 SaaS task IDs here.

---

## 1. Feasibility and safety verdict

The requested behavior is feasible without changing `WorkflowEngine`, `workflow_instances`, or append-only `workflow_actions`.

It is safe if implemented with these boundaries:

1. **“First user” means the installer-created account.** New installations write an explicit owner record when `Installer::createAdministrator()` creates that account. Existing v2.5.5 installations migrate the oldest existing `super-admin` to the owner record.
2. **“Invincible” means protected and directory-hidden, not audit-erased.** The owner cannot be edited, demoted, deactivated, anonymized, deleted, imported-over, or recovered by another user. Other users do not see the owner in user-management, manager, delegation, mention, user-field, or new workflow-assignment pickers.
3. **The owner is not erased from history.** If the owner submitted, commented on, or approved existing work, authorized readers must still see the real actor. Hiding or rewriting those records would break ApproveDesk’s audit guarantees. Existing workflows that already reference the owner must continue to resolve so in-flight requests do not become stuck.
4. **There is exactly one owner.** The internal `super-admin` role becomes owner-reserved and is never grantable from the UI/import. The owner delegates the existing `admin`, `department-head`, `approver`, `submitter`, `viewer`, and `auditor` roles. Custom role/permission editing is out of scope.
5. **Ordinary admins cannot reset credentials.** The current optional password field on user edit must be removed for existing accounts. Password and 2FA recovery move to owner-only actions.
6. **Password reset is secure, not password disclosure.** “Reset password” invalidates the old password, revokes sessions/tokens, and sends the existing one-use password-reset link. The owner never sees or chooses another person’s permanent password.
7. **The active-user cap includes the owner.** Inactive users do not consume a slot. The default after upgrade is unlimited, preserving v2.5.5 behavior until the owner sets a limit. A configured limit cannot be lowered below the current active count and never auto-deactivates users.
8. **Limit enforcement is server-side and race-safe.** Manual creation/reactivation, self-registration, first-time OAuth account creation, and bulk import must all contend on the same singleton limit row inside a transaction before activating accounts.

---

## 2. Product language and task IDs

Use these self-hosted supplementary IDs:

- `SH-OWN-*` — singleton owner and protected delegation
- `SH-REC-*` — owner-only password/2FA recovery
- `SH-CAP-*` — active-user capacity and usage meter
- `SH-DOC-*` — self-hosted Help/deployment documentation and roadmap isolation

User-facing terminology:

- **System owner** — the protected first account created during setup
- **Administrator** — a delegated user with the existing `admin` role
- **Active-user limit** — maximum number of accounts with **Account is active** on
- Avoid **platform admin**, **tenant**, **organization owner**, **plan**, **seat billing**, and **entitlement** in this self-hosted feature.

---

## 3. Data model

### Singleton ownership

Create `system_owners`:

```text
id          unsigned tiny integer, primary key; application always writes 1
user_id     foreign key -> users.id, unique, restrict on delete
timestamps
```

Why a singleton table instead of `users.is_owner`:

- one row makes the one-owner invariant explicit;
- ownership is not accidentally copied through user factories/imports;
- the owner user cannot be deleted while referenced;
- role assignment and ownership remain distinct concepts;
- no cross-database partial unique index is required.

### Site capacity

Create `site_limits`:

```text
id                unsigned tiny integer, primary key; application always writes 1
max_active_users  unsigned integer, nullable; null means unlimited
timestamps
```

The row must exist after migration/installation even when the limit is unlimited, because it is the serialization lock for concurrent activations.

### Recovery audit events

Create append-only `account_recovery_events`:

```text
id          primary key
actor_id    foreign key -> users.id, restrict on delete
target_id   foreign key -> users.id, restrict on delete
action      string: password_reset | second_factor_reset
metadata    nullable JSON; non-secret result/context only
created_at  timestamp
```

No update/delete application path. Never store passwords, reset tokens, TOTP secrets, recovery codes, passkey credential data, session IDs, full signed URLs, or request query strings.

---

## 4. Upgrade behavior from v2.5.5

1. Create the three tables in additive migrations; do not edit released migrations.
2. Populate `site_limits` row `id=1`, `max_active_users=null`.
3. Find the oldest user holding `super-admin` ordered by `users.id`; write that user into `system_owners(id=1)`.
4. If no `super-admin` exists, do **not** silently elevate another account. Leave ownership unassigned, log a critical operator message, and provide `php artisan owner:assign {email}` as the explicit server-admin repair command.
5. For every additional legacy `super-admin`:
   - assign `admin` if the user does not already have it;
   - preserve any other existing roles;
   - remove `super-admin`;
   - log the affected user IDs (not credentials).
6. Change universal authorization from `hasRole('super-admin')` to `isSystemOwner()`. This makes ownership singular while preserving broad delegated administration for converted accounts.
7. The owner keeps `super-admin` internally for compatibility, but all grant UIs and import paths must exclude it.
8. Do not alter historical submissions, workflow actions, templates, assignments, or audit evidence.

Rollback note: schema rollback may drop the supplementary tables in development, but production rollback remains forward-only per ApproveDesk deployment policy. Document a server-admin ownership-repair procedure before release.

---

## 5. Implementation tasks

### Task 1: Establish the supplementary self-hosted delivery line

**Objective:** Isolate this work from ApproveDesk SaaS and record the scope before runtime changes.

**Files:**
- Create during implementation: `SELF_HOSTED_SUPPLEMENTARY_IMPLEMENTATION_PLAN.md` from this approved plan, or retain this file as the execution source if `.hermes/plans` is the accepted project convention
- Modify: `IMPLEMENTATION_PLAN.md` only to add a short pointer under the v2.5.5 self-hosted maintenance section; do not edit Phase 10–15 task contents
- Modify: `CHANGELOG.md`

**Steps:**

1. Create branch `feature/self-hosted-owner-controls` from tagged `v2.5.5`/current `main`.
2. Add the `SH-OWN`, `SH-REC`, and `SH-CAP` headings as **Planned**; do not mark complete.
3. State explicitly that ApproveDesk SaaS work remains excluded and separately roadmapped.
4. Verify no file under a SaaS repository/profile is changed.
5. Commit documentation scaffold:

```bash
git add IMPLEMENTATION_PLAN.md CHANGELOG.md SELF_HOSTED_SUPPLEMENTARY_IMPLEMENTATION_PLAN.md
git commit -m "docs: plan self-hosted owner controls"
```

---

### Task 2: Characterize v2.5.5 authorization and account entry points

**Objective:** Lock down existing behavior with failing/characterization tests before changing roles or activation.

**Files:**
- Modify: `tests/Feature/InstallerTest.php`
- Modify: `tests/Feature/Admin/UserManagementTest.php`
- Modify: `tests/Feature/Users/UserOnboardingTest.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`
- Modify/create tests around OAuth account creation (locate the existing `ResolveOAuthUserAction` test first)

**Test cases:**

- Installer creates the first user with `super-admin` under current behavior.
- An `admin` can currently edit another user but cannot edit a `super-admin`.
- Current manual creation, import, registration, and OAuth flows create active users.
- Current user-edit form can change an existing password; write this as a security regression test that will be inverted later.
- Last active super-admin protections currently apply.

**Commands:**

```bash
php artisan test --parallel --filter='InstallerTest|UserManagementTest|UserOnboardingTest|RegistrationTest'
```

Expected before feature work: characterization tests pass. Commit tests before production changes.

---

### Task 3: Add singleton owner, site-limit, and recovery-event schema

**Objective:** Add cross-database-safe foundations without changing workflow data.

**Files:**
- Create: `database/migrations/<timestamp>_create_system_ownership_and_site_limits.php`
- Create: `database/migrations/<timestamp>_create_account_recovery_events_table.php`
- Create: `app/Models/SystemOwner.php`
- Create: `app/Models/SiteLimit.php`
- Create: `app/Models/AccountRecoveryEvent.php`
- Modify: `database/seeders/SettingsSeeder.php` only if installation seeding needs an explicit site-limit bootstrap; prefer migration/model singleton initialization
- Test: `tests/Feature/Security/SystemOwnerMigrationTest.php`

**Model rules:**

- `SystemOwner` exposes `SINGLETON_ID = 1` and rejects a second ID at the service layer.
- `SiteLimit` exposes `SINGLETON_ID = 1`; `max_active_users=null` means unlimited.
- `AccountRecoveryEvent` refuses updates/deletes similarly to immutable audit models, but remains separate from `workflow_actions`.

**Failing tests first:**

- exactly one owner row can be represented;
- owner FK prevents deleting the owner directly;
- active-user limit starts unlimited;
- recovery events cannot be updated/deleted through the model;
- migration picks the oldest existing super-admin;
- extra legacy super-admins become admins and lose `super-admin`;
- no-super-admin migration does not elevate an arbitrary user.

**Verification:**

```bash
php artisan test --parallel --filter='SystemOwnerMigrationTest'
```

Expected: all ownership migration tests pass on SQLite. CI’s MySQL job must also pass before merge.

---

### Task 4: Make the installer-created first user the owner

**Objective:** Ensure every new installation records the first administrator as the unique owner atomically.

**Files:**
- Modify: `app/Support/Installer.php`
- Modify: `resources/views/install/admin.blade.php`
- Modify: `tests/Feature/InstallerTest.php`
- Create: `app/Console/Commands/AssignSystemOwnerCommand.php`
- Test: `tests/Feature/Console/AssignSystemOwnerCommandTest.php`

**Implementation contract:**

- `Installer::createAdministrator()` creates user, verifies email, assigns internal `super-admin`, writes `system_owners(id=1,user_id=...)`, and ensures `site_limits(id=1)` exists in one DB transaction.
- Installer copy calls this account **System owner**, not merely Administrator.
- `owner:assign {email}` is server-admin recovery only:
  - refuses if a different owner already exists unless an explicit, separately confirmed transfer feature is implemented later;
  - requires target to exist and be active;
  - assigns `super-admin` to target;
  - converts other `super-admin` users to `admin`;
  - never changes passwords or second factors.
- Ownership transfer through the web UI is out of scope for this release.

**Tests:**

- full installer flow creates exactly one owner;
- owner is first user and active;
- installer cannot create a second owner;
- repair command fills a missing owner but cannot overwrite one.

**Verification:**

```bash
php artisan test --parallel --filter='InstallerTest|AssignSystemOwnerCommandTest'
```

---

### Task 5: Centralize owner authorization and role delegation

**Objective:** Make owner authority explicit and prevent delegated administrators from escalating themselves or others.

**Files:**
- Create: `app/Services/Users/SystemOwnerService.php`
- Create: `app/Services/Users/GrantableRoleService.php`
- Modify: `app/Models/User.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Policies/UserPolicy.php`
- Modify: `app/Actions/Users/CreateUserAction.php`
- Modify: `app/Actions/Users/UpdateUserAction.php`
- Modify: `app/Services/Users/UserImportService.php`
- Modify: `resources/views/pages/admin/users.blade.php`
- Test: `tests/Feature/Admin/SystemOwnerAuthorizationTest.php`
- Test: `tests/Unit/Services/Users/GrantableRoleServiceTest.php`

**Required API:**

```php
$user->isSystemOwner(): bool
SystemOwnerService::ownerId(): ?int
GrantableRoleService::for(User $actor): array
```

**Role rules:**

- Owner may grant/revoke every existing role except `super-admin`.
- `super-admin` is internal and owner-reserved; it is absent from user UI, imports, templates, and role pickers.
- Delegated `admin` may grant ordinary operational roles but may not grant/revoke `admin` or `super-admin`.
- Only owner can grant/revoke the `admin` role.
- Department head, approver, submitter, viewer, and auditor retain their current permissions.
- Do not add a custom-role or permission-matrix editor.

**Owner protection rules:**

- no other user may update, deactivate, anonymize, delete, change roles, reset credentials, or import-update owner;
- owner cannot deactivate/anonymize/demote self;
- direct action calls enforce this even if UI or policy is bypassed;
- replace `Gate::before(hasRole('super-admin'))` with `Gate::before(isSystemOwner())`;
- avoid role-name checks in controllers/Livewire; use policies/services per `AGENTS.md`.

**Tests:**

- owner receives universal Gate access;
- a legacy/internal `super-admin` role without ownership does not receive universal access;
- owner can grant `admin` and ordinary roles;
- admin cannot grant or revoke `admin`/`super-admin`;
- admin can grant permitted ordinary roles;
- owner remains active and internally `super-admin`;
- import cannot assign `super-admin` or update owner through duplicate mode.

---

### Task 6: Implement bounded owner invisibility without breaking audit history

**Objective:** Hide owner from other users’ directory/selection surfaces while retaining historical and in-flight references.

**Files likely to change after a complete query audit:**
- Create: `app/Services/Users/UserVisibility.php` or add explicit local scopes to `app/Models/User.php`
- Modify: `resources/views/pages/admin/users.blade.php`
- Modify: `app/Services/Workflow/AssigneeDirectory.php`
- Modify: `app/Services/Collaboration/MentionParser.php`
- Modify: `app/Actions/Collaboration/SaveOutOfOfficeAction.php`
- Modify: `resources/views/pages/submissions/create-custom.blade.php`
- Modify: `resources/views/pages/submissions/show.blade.php`
- Modify: `resources/views/pages/admin/departments.blade.php`
- Modify: workflow/request-type user picker pages discovered by search
- Test: `tests/Feature/Admin/SystemOwnerVisibilityTest.php`
- Test: relevant workflow/delegation/mention feature tests

**Scopes/semantics:**

```php
scopeVisibleTo(Builder $query, User $viewer): Builder
scopeAssignable(Builder $query): Builder
```

- `visibleTo(owner)` may include the owner’s own row; `visibleTo(non-owner)` excludes it.
- `assignable()` excludes owner from new manager, delegate, mention, user-field, department-manager, and workflow user pickers.
- Do **not** add a global scope.
- Do **not** change `WorkflowAction`, `Submission`, comments, evidence packs, exports, or historical relationships to hide actor attribution.
- Do **not** make old explicit workflow assignments stop resolving. Existing in-flight assignments involving owner continue until completed; the owner is only excluded from new selections.
- If an old saved form/template displays an existing owner assignment for editing, show a protected legacy value or warning rather than silently deleting it.

**Tests:**

- non-owner user-management list/search never returns owner;
- owner can still access own profile/security pages;
- owner absent from every new assignee/manager/delegate/mention/user-field picker;
- a direct submitted owner ID is rejected for new assignments;
- historical requests and workflow actions still show the owner as actor;
- an in-flight workflow already assigned to owner continues to resolve and can complete;
- user counts shown to ordinary admins exclude the hidden owner only where the label says “managed users”; capacity meter still counts owner and is owner-only.

**Query-audit command:** use `search_files`, not shell grep, to enumerate every `User::query()`, `where('is_active', true)`, role picker, mention picker, and user-ID validation. Classify each as directory, new assignment, authentication, or historical reference before editing.

---

### Task 7: Remove ordinary-admin password changes from general user editing

**Objective:** Close the existing path where any user manager can set another user’s password.

**Files:**
- Modify: `resources/views/pages/admin/users.blade.php`
- Modify: `app/Actions/Users/UpdateUserAction.php`
- Modify: `app/DTOs/UserData.php` or split create/update DTOs if that removes ambiguity cleanly
- Modify: `tests/Feature/Admin/UserManagementTest.php`

**Rules:**

- Password and confirmation remain required for creating a manually managed account.
- Editing an existing account has no password fields.
- `UpdateUserAction` must reject/ignore no hidden password input; preferably use a DTO that cannot carry a password for updates.
- Admin, owner, import, and ordinary HTTP requests cannot change an existing password through the generic edit action.
- Password recovery exists only through Task 8 or the user’s own Fortify password flow.

**Tests:**

- invert the characterization test: admin cannot update another user’s password through edit;
- owner also cannot bypass the dedicated recovery path by editing;
- creating a user still hashes the supplied password;
- profile owner can still change their own password under **Settings → Security**.

---

### Task 8: Add owner-only password and 2FA/passkey recovery

**Objective:** Provide safe recovery controls for every non-owner account.

**Files:**
- Create: `app/Actions/Users/ResetUserPasswordAction.php`
- Create: `app/Actions/Users/ResetUserSecondFactorAction.php`
- Create: `app/Http/Controllers/Admin/ResetUserPasswordController.php`
- Create: `app/Http/Controllers/Admin/ResetUserSecondFactorController.php`
- Create: `app/Http/Middleware/EnsureSystemOwnerReauthenticated.php`
- Create: target security notification class(es) under `app/Notifications/`
- Modify: `app/Policies/UserPolicy.php`
- Modify: `app/Services/Security/DeviceSessionManager.php` only if a reusable revoke-sessions-and-tokens method is appropriate
- Modify: `routes/web.php`
- Modify: `resources/views/pages/admin/users.blade.php`
- Test: `tests/Feature/Admin/UserAccountRecoveryTest.php`

**Routes:**

```text
POST /admin/users/{user}/recovery/password
POST /admin/users/{user}/recovery/second-factor
```

Both require `auth`, `verified`, `password.confirm`, owner authorization, CSRF, and recent second-factor assurance. The target may not be the owner or the actor.

**Password-reset behavior:**

1. Lock target user row.
2. Confirm target is not owner.
3. Generate/store a password-broker reset token and queue the standard reset notification successfully.
4. Replace current password with a cryptographically random unknown value so the old password stops working immediately.
5. Clear remember token.
6. Revoke all sessions and personal access tokens.
7. Do not delete OAuth identities, profile, roles, request history, or 2FA unless the owner separately chooses second-factor reset.
8. Append `account_recovery_events(action=password_reset)` after success.
9. Notify target that the owner initiated recovery; do not disclose actor credentials or a raw token in logs.

If notification dispatch cannot be accepted, return an error and do not invalidate the password. Use transaction/after-commit behavior so the user is not silently stranded.

**Second-factor reset behavior:**

1. Lock target row and confirm target is not owner.
2. Set `two_factor_secret`, `two_factor_recovery_codes`, and `two_factor_confirmed_at` to `null`.
3. Delete all target passkeys.
4. Revoke all sessions and personal access tokens.
5. Preserve password, OAuth identities, roles, activity, and history.
6. Append `account_recovery_events(action=second_factor_reset)`.
7. Notify target.
8. When organization-wide second-factor enforcement is on, the next password login is sent to **Settings → Security** until a new authenticator or passkey is enrolled.

**UI:**

- Owner sees a separate **Account recovery** card when editing a non-owner.
- Use explicit labels: **Invalidate password and email reset link** and **Remove authenticator, recovery codes, and passkeys**.
- Each button has a consequence-specific confirmation; no combined “reset everything” button in the first release.
- Ordinary admins do not receive hidden/disabled buttons; the card is absent.
- Display success only after the action and event record are committed.

**Tests:**

- owner-only authorization at route, policy, and action levels;
- password confirmation and current second-factor assurance required;
- admin with `users.manage` receives 403;
- target owner/self is always refused;
- old password stops working and reset link works;
- mail failure does not invalidate password;
- all target sessions/tokens revoked;
- 2FA fields and passkeys cleared together;
- password reset leaves 2FA intact; 2FA reset leaves password intact;
- inactive target remains inactive;
- event records append and cannot be mutated;
- event/log data contains no secret, token, code, credential, session ID, or signed URL.

---

### Task 9: Implement concurrency-safe active-user capacity

**Objective:** Enforce a total active-user limit without affecting inactive/history records.

**Files:**
- Create: `app/Services/Users/ActiveUserLimitService.php`
- Create: `app/Actions/Settings/UpdateActiveUserLimitAction.php`
- Modify: `app/Actions/Users/CreateUserAction.php`
- Modify: `app/Actions/Users/UpdateUserAction.php`
- Modify: `app/Actions/Fortify/CreateNewUser.php`
- Modify: `app/Actions/Auth/ResolveOAuthUserAction.php`
- Modify: `app/Services/Users/UserImportService.php`
- Test: `tests/Unit/Services/Users/ActiveUserLimitServiceTest.php`
- Test: `tests/Feature/Admin/ActiveUserLimitTest.php`
- Extend registration/OAuth/import tests

**Service contract:**

```php
public function maximum(): ?int;
public function activeCount(): int;
public function remaining(): ?int;
public function runActivation(int $slots, Closure $activate): mixed;
public function updateMaximum(?int $maximum, User $actor): SiteLimit;
```

`runActivation()` must:

1. begin/reuse a DB transaction;
2. `lockForUpdate()` the singleton `site_limits` row;
3. count current `users.is_active = true` inside that transaction;
4. reject when `active + requested_slots > maximum`;
5. execute the activation callback before releasing the lock.

Because every contender locks the same singleton row, concurrent registrations/imports cannot both consume the final slot on MySQL/MariaDB. SQLite tests must remain supported.

**Capacity rules:**

- null maximum means unlimited;
- minimum configured value is 1 because owner is always active;
- maximum may not be set below current active count;
- owner counts as one active user;
- creating an inactive user is allowed when at capacity;
- inactive-to-active transition consumes one slot;
- active-to-active edit consumes no new slot;
- deactivation frees a slot immediately;
- no user is auto-deactivated when a limit changes;
- existing active users can still log in when at capacity;
- first-time self-registration and first-time OAuth creation are rejected at capacity;
- existing OAuth identities/users can still sign in;
- imported new users consume slots; preview reports deterministic capacity errors for surplus rows, and commit rechecks under lock;
- changing email/roles/profile never consumes a slot;
- owner cannot be deactivated to free a slot.

**Avoid bypasses:**

- enumerate all production `User::create`, `is_active` writes, registration, OAuth, import, and reactivation paths;
- route each production activation through the service;
- factories/seeders may use direct writes only in test/demo setup;
- add an architecture/regression test listing the approved production activation call sites so a later direct active-user write is noticed in review.

**Tests:**

- unlimited default preserves v2.5.5 behavior;
- limit includes owner;
- exact last slot succeeds and next fails;
- deactivation frees one slot;
- reactivation rechecks;
- lowering below active count fails without change;
- manual create, registration, OAuth, import, and edit all enforce the same rule;
- inactive manual creation at capacity succeeds;
- imports cannot partially exceed capacity unnoticed;
- two simulated activations contending for one slot yield one success/one rejection in MySQL CI; if SQLite cannot faithfully simulate row-lock concurrency, retain SQLite functional coverage and run the concurrency case in the existing MySQL CI lane.

---

### Task 10: Add the owner-only capacity screen and measurement

**Objective:** Let the owner configure and observe capacity without exposing owner identity.

**Files:**
- Create or modify: `resources/views/pages/settings/security-privacy.blade.php` **or** create `resources/views/pages/admin/site-capacity.blade.php` after checking navigation fit
- Modify: `routes/web.php`
- Modify: sidebar/navigation component discovered during implementation
- Modify: `app/Support/HelpCatalogue.php` if a new Help article is added
- Test: `tests/Feature/Admin/ActiveUserLimitSettingsTest.php`

**Recommended UI:** Add an owner-only **Active-user capacity** card under user administration or a dedicated owner-only **Site capacity** page.

Display:

- active users: `X`;
- configured maximum: `Y` or **Unlimited**;
- remaining slots: `Y - X`;
- inactive accounts count for context;
- explanatory copy that the system owner is included;
- integer input with **Leave unlimited** option;
- warning that lowering the limit does not deactivate anyone and cannot go below current usage.

**Authorization:*** The page/action is owner-only, not merely `settings.manage`. Ordinary admins may continue activating/deactivating users only within the owner’s limit but cannot see or change the configured maximum unless Femi later requests read-only visibility.

**Tests:**

- owner can view and update limit;
- admin gets 403 and nav link is absent;
- values are exact and owner is included;
- invalid/zero/negative/below-current values are rejected;
- unlimited can be restored by owner;
- simultaneous setting update and account activation serialize on the same row.

---

### Task 11: End-user Help, operator recovery, and roadmap isolation

**Objective:** Document the shipped screens and the server-admin fallback without mixing SaaS roadmap concepts into self-hosted Help.

**Files:**
- Modify: `docs/kb/users-and-roles.md`
- Modify: `docs/kb/security-and-privacy.md`
- Modify: `docs/kb/account-settings.md`
- Modify: `docs/kb/definitions.md`
- Modify: `app/Support/HelpCatalogue.php` only if catalogue metadata/article registration changes
- Modify: `docs/DEPLOYMENT.md`
- Modify: `IMPLEMENTATION_PLAN.md`
- Modify: `CHANGELOG.md`
- Test: `tests/Feature/HelpCentreTest.php`

**Help content (screen-facing):**

- define System owner, Administrator, Account recovery, and Active-user limit;
- explain owner-only recovery buttons and their consequences;
- explain that reset password sends a link and signs the person out;
- explain that second-factor reset removes authenticator/recovery codes/passkeys and signs the person out;
- explain role grant boundaries;
- explain active/inactive capacity and that history remains;
- do not mention table names, field keys, middleware, framework internals, or raw server commands.

**Deployment/operator content:**

- document `php artisan owner:assign {email}` only in `docs/DEPLOYMENT.md`, never Help;
- document pre-upgrade identification/conversion of extra legacy super-admins;
- document owner-lockout server recovery separately from recovering ordinary users;
- preserve exact product name **ApproveDesk SaaS** and state that SaaS maintains a separate roadmap.

**Completion marking:** Mark each implemented `SH-*` task `— Complete (YYYY-MM-DD)` only after tests, Help, changelog, and both-checkout synchronization are complete. Do not mark SaaS phases or tasks.

---

### Task 12: Full regression, security review, and two-checkout delivery

**Objective:** Prove the supplementary features do not break approvals, audit integrity, authentication, or shared-hosting operation.

**Required commands:**

```bash
php artisan test --parallel
./vendor/bin/pint --dirty
npm run build
php artisan optimize:clear
curl -I http://127.0.0.1:8000/login
```

Expected:

- full suite green;
- Pint clean;
- asset build succeeds if UI assets changed;
- login responds successfully;
- no pending unsafe migration warning after applying the new additive migrations in a disposable test copy.

**Security review checklist:**

- [ ] Owner identity is absent from ordinary directory/assignment responses, not just visually hidden.
- [ ] Direct URLs and forged Livewire/controller payloads cannot target owner.
- [ ] No normal admin can change credentials or administrative-role membership.
- [ ] Recovery requires owner + recent password confirmation + current second-factor assurance.
- [ ] Recovery revokes sessions/tokens and emits a durable event without secrets.
- [ ] Existing audit attribution and in-flight owner assignments remain intact.
- [ ] Active-user cap is enforced by every production activation path.
- [ ] Limit checks and limit updates lock the same singleton row.
- [ ] No SaaS tenancy, billing, plan, organization, or entitlement code was introduced.
- [ ] No update/delete path touches `workflow_actions`.

**Manual acceptance scenarios:**

1. Fresh install creates owner; admin user cannot see or edit owner.
2. Owner creates an admin; admin delegates an approver but cannot create another admin.
3. Owner resets a user’s 2FA; all devices sign out; user signs in with password and is required to re-enrol when second-factor enforcement is on.
4. Owner invalidates a user’s password; old password fails; emailed reset link sets a new password.
5. Owner sets maximum to current count + 1; one activation succeeds; next manual/registration/OAuth/import activation fails consistently.
6. Deactivate one ordinary user; one new activation succeeds.
7. Open a historical request acted on by owner; owner attribution remains visible and evidence still verifies.
8. Complete an in-flight workflow already assigned to owner; it does not become stuck.

**Delivery:**

1. Commit in `/home/oluwafemi/ApproveDesk` on `feature/self-hosted-owner-controls`.
2. Run pre-commit review using `requesting-code-review` skill.
3. Sync `/home/oluwafemi/Documents/ApproveDesk` using the established fetch/cherry-pick workflow.
4. Verify changed-file parity with `cmp -s` and clean status in both checkouts.
5. Confirm live server response after cache clear.
6. Merge/release only after green CI; assign the next self-hosted version according to release policy rather than reusing the `v2.5.5` tag.

---

## 6. Acceptance criteria

### `SH-OWN`

- Installer-created first account is the one and only system owner.
- Owner is universal-authority principal; role name alone does not grant universal authority.
- Owner cannot be edited, demoted, deactivated, anonymized, deleted, imported-over, or credential-reset by anyone else.
- Owner is hidden from other users’ management and new-assignment surfaces.
- Historical/audit attribution and existing workflow references remain correct.
- Owner can grant `admin`; admins cannot grant administrative privilege to themselves or others.
- `super-admin` is internal, owner-only, and never grantable.

### `SH-REC`

- Only recently reauthenticated owner can recover another account.
- General user edit cannot change an existing password.
- Password reset invalidates old password, revokes sessions/tokens, and sends a one-use reset link.
- Second-factor reset atomically clears TOTP, recovery codes, and passkeys and revokes sessions/tokens.
- Recovery never changes roles, active status, profile, OAuth identity, requests, or audit history.
- Every recovery action creates an immutable, secret-free security record and notifies the target.

### `SH-CAP`

- Owner can set unlimited or an integer maximum not below current active usage.
- Active count includes owner and excludes inactive accounts.
- No activation path can exceed the limit, including under concurrent requests.
- Deactivation frees capacity; no automatic deactivation occurs.
- Existing active users can sign in at capacity.
- Capacity UI reports exact, non-truncated usage.

### `SH-DOC`

- In-app Help explains only user-visible screens and behavior.
- Server fallback lives in deployment documentation.
- Self-hosted supplementary task IDs do not alter or claim completion of ApproveDesk SaaS roadmap phases.

---

## 7. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A global owner-hidden scope removes historical actors or breaks relations | Never use a global scope; use explicit `visibleTo`/`assignable` scopes only on directory/new-selection queries |
| Existing workflows assign the first admin | Continue resolving saved/in-flight assignments; block only new selections |
| More than one legacy super-admin exists | Deterministically select oldest as owner, convert others to `admin`, preserve other roles, document before release |
| No super-admin exists during upgrade | Do not elevate arbitrary user; log critical state and require `owner:assign {email}` |
| Admin currently can set passwords | Remove edit-password fields and reject update DTO/action paths before adding owner recovery |
| Recovery locks user out when mail fails | Do not invalidate password unless reset-notification dispatch is accepted; test failure path |
| Recovery endpoint is abused from a stolen owner session | Require owner policy, recent password confirmation, current second-factor assurance, CSRF, target confirmation, audit event, and notification |
| Two registrations consume final slot concurrently | Lock one singleton `site_limits` row and perform count + activation in the same transaction |
| Bulk import exceeds available capacity | Preview deterministic surplus errors and recheck total under lock at commit |
| Lowering cap disrupts existing users | Reject values below current active count; never auto-deactivate |
| Hidden owner creates confusing counts | Owner-only capacity meter includes owner and says so; ordinary user list is labeled managed users if it excludes owner |
| SaaS roadmap contamination | Keep `SH-*` IDs and self-hosted branch/file; do not implement Phase 10–15 concepts |

---

## 8. Explicitly out of scope

- ApproveDesk SaaS tenancy, organizations, platform console, billing, plans, subscriptions, or entitlements
- multiple system owners or web-based ownership transfer
- custom roles/permission editor
- automatic user deactivation when over capacity
- storage/request-volume/API limits
- changing upload limits or file types
- hiding owner from historical audit evidence
- deleting or rewriting `workflow_actions`
- owner recovery by another in-app user

---

## 9. Recommended release slicing

For lower risk, deliver in three reviewable commits/releases on one self-hosted feature branch, or three tightly ordered self-hosted branches:

1. **Owner foundation (`SH-OWN`)** — singleton ownership, role hardening, bounded visibility, installer/upgrade behavior.
2. **Account recovery (`SH-REC`)** — remove generic password editing, then add owner-only password and second-factor recovery.
3. **Capacity (`SH-CAP`)** — singleton limit, all activation-path enforcement, usage UI.

Do not ship `SH-REC` before owner uniqueness and authorization are established. Do not ship `SH-CAP` until all production activation entry points have been enumerated and covered by tests.

