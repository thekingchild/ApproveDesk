# 0009. A single System owner and a permission ceiling for everyone else

Date: 2026-10-17

Status: Accepted (recorded retroactively; decision in force since v2.6.0 (SH-OWN), permission ceiling since v2.7.0 (SH-RBAC), extended to built-in roles in v2.8.2 and to identity changes in Unreleased (RV-SEC-04))

## Context

ApproveDesk is a single-company, self-hosted product. Before v2.6.0, any `super-admin` or `admin` could grant any role, reset passwords from user edit, and change any account, so a delegated administrator could escalate their own access or take over a stronger account. Operators needed one account that cannot be locked out or edited by the people it delegates to, and a way to let others manage roles without handing them everything. Custom roles (v2.7.0) made this sharper: a role manager able to put any permission in a role could grant themselves anything.

## Decision

- **One owner, stored as a singleton row.** `system_owners` has a fixed primary key of 1 and a unique, delete-restricted `user_id` (`2026_08_26_090000_create_system_ownership_and_site_limits.php`); `SystemOwner` refuses any other ID (`app/Models/SystemOwner.php:18`). The installer creates it for the first account; `php artisan owner:assign {email}` fills a missing owner and never overwrites one. `SystemOwnerService` memoises the owner ID per request and is reset when the row is saved or deleted. `User::isSystemOwner()` compares against that ID.
- **Owner authority comes from the row, not the role.** `Gate::before` (`app/Providers/AppServiceProvider.php:137`) returns true for every ability when the user is the owner, and grants catalogue permissions to others through `FeaturePermission`. The `super-admin` role holds no permissions of its own; it marks the owner and can never be assigned, cloned, or edited.
- **Three tiers of authority.** The owner holds everything. The built-in `admin` role holds every catalogue permission except `roles.manage.custom` and `branding.custom_code.manage` (`RolesAndPermissionsSeeder`). Other built-in roles and custom roles hold what their profile lists. A person's access is the union of their roles.
- **The owner is hidden and untouchable.** `User::visibleTo()` drops the owner from the Users directory for everyone else; `User::assignable()` drops them from assignee, mention, manager, and out-of-office pickers. `Gate::before` denies `update`, `anonymize`, and `recover` on the owner for every actor, and `RoleAssignmentService` refuses to change the owner's roles. Historical attribution is not filtered.
- **Permission ceiling.** `RolePermissionService::ceiling()` is the set of catalogue permissions an actor may hand out. For non-owners it is the *delegable* catalogue permissions they hold, directly or through a legacy alias. For the owner it is every delegable permission plus `roles.manage.custom`. `branding.custom_code.manage` is non-delegable and is in no one's ceiling, so only the owner ever has it.
- **Every grant path checks the ceiling.** `RolePermissionService` refuses to create, edit, or clone a custom role containing permissions above the actor's ceiling. `GrantableRoleService::for()` lists only roles whose permissions all sit within it; `RoleAssignmentService::sync()` re-checks under row locks, keeps any existing role the actor could not have granted, and stops non-owners from changing their own roles. Built-in roles need `users.manage`/`users.update`; custom roles need `roles.assign.custom`. User import goes through the same services.
- **Administrator is owner-only.** Only the owner can grant or remove `admin` (`GrantableRoleService`, `RoleAssignmentService`), and non-owners cannot update an admin user through `UpdateUserAction` at all.
- **Identity changes follow the ceiling.** `UserPolicy::manageIdentity()` (`app/Policies/UserPolicy.php:40`) lets a non-owner change email, active status, or anonymize only when the target is neither `admin` nor `super-admin` and holds nothing above the actor's ceiling (`exceedsCeiling()`). Otherwise an actor could point a stronger account's email at their own mailbox.
- **Owner-only operations:** account recovery (password and second-factor reset, behind `password.confirm` and `owner.reauthenticated`); the active-user limit; announcements; application themes; analytics and custom code on Branding; approval ID format; granting `admin` and `roles.manage.custom`.
- Role changes are written to append-only `role_change_events`.

## Consequences

- A delegated administrator cannot escalate themselves or anyone else beyond their own access, and cannot take over the owner or a peer administrator.
- There is exactly one owner and no in-app transfer. `owner:assign` only fills a missing row, so replacing an owner needs direct database access. No other account can run account recovery on the owner (`UserPolicy::recover`).
- Owner-only rules are spread across `Gate::before`, Policies, Actions, and Blade components. ADR 0008 proposes moving the component checks behind named Gates.
- A new catalogue permission must declare `delegable` deliberately. Marking it `false` makes it owner-only with no way to delegate it.

## Open questions

- `docs/kb/users-and-roles.md` says a non-owner can still edit the name, phone, department, and line manager of someone with more access. That holds for users above the ceiling, but `UpdateUserAction` refuses every edit of an `admin` user by a non-owner, with the message "Only the System owner may change the administrator role." Which is intended?
- Some code still checks role names instead of the owner row, for example `ReportService` uses `hasAnyRole(['super-admin', 'admin'])` (RV-ARC-20, see ADR 0008). Since `super-admin` is assigned only alongside the owner row, both checks agree today. That stops being true if the role and the row ever drift apart.
