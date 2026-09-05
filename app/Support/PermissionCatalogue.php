<?php

namespace App\Support;

final class PermissionCatalogue
{
    /** @var array<string, array{label: string, description: string, group: string, risk: string, delegable: bool, depends_on_any: list<string>, legacy: list<string>}> */
    private const ENTRIES = [
        'users.view' => ['label' => 'View users', 'description' => 'Open the managed-user directory.', 'group' => 'Users and access', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['users.manage']],
        'users.create' => ['label' => 'Create users', 'description' => 'Create managed user accounts.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['users.view'], 'legacy' => ['users.manage']],
        'users.update' => ['label' => 'Update users', 'description' => 'Edit managed user profiles and status.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['users.view'], 'legacy' => ['users.manage']],
        'users.activate' => ['label' => 'Activate users', 'description' => 'Activate or deactivate managed accounts.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['users.update'], 'legacy' => ['users.manage']],
        'users.import' => ['label' => 'Import users', 'description' => 'Create or update users from a spreadsheet.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['users.create'], 'legacy' => ['users.manage']],
        'users.anonymize' => ['label' => 'Anonymize users', 'description' => 'Remove personal details while retaining history.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['users.update'], 'legacy' => ['users.manage']],
        'roles.view' => ['label' => 'View roles', 'description' => 'View built-in and custom role definitions.', 'group' => 'Users and access', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'roles.manage.custom' => ['label' => 'Manage custom roles', 'description' => 'Create and maintain custom roles within the holder’s permission ceiling.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => false, 'depends_on_any' => ['roles.view'], 'legacy' => []],
        'roles.assign.custom' => ['label' => 'Assign custom roles', 'description' => 'Assign active custom roles within the holder’s permission ceiling.', 'group' => 'Users and access', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['roles.view'], 'legacy' => []],
        'departments.view' => ['label' => 'View departments', 'description' => 'View departments and their managers.', 'group' => 'Departments', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['departments.manage']],
        'departments.manage' => ['label' => 'Manage departments', 'description' => 'Create and edit departments.', 'group' => 'Departments', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['departments.view'], 'legacy' => []],
        'submissions.create' => ['label' => 'Create requests', 'description' => 'Submit new requests.', 'group' => 'Requests', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'submissions.view.own' => ['label' => 'View own requests', 'description' => 'View requests the person submitted.', 'group' => 'Requests', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'submissions.view.all' => ['label' => 'View all requests', 'description' => 'View requests across the installation.', 'group' => 'Requests', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'submissions.withdraw' => ['label' => 'Withdraw requests', 'description' => 'Withdraw eligible requests the person submitted.', 'group' => 'Requests', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['submissions.view.own'], 'legacy' => []],
        'submissions.fields.sensitive' => ['label' => 'View sensitive fields', 'description' => 'View fields marked sensitive when request access already exists.', 'group' => 'Requests', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['submissions.view.all', 'submissions.view.own'], 'legacy' => []],
        'comments.create' => ['label' => 'Create comments', 'description' => 'Comment on requests the person can view.', 'group' => 'Collaboration', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['submissions.view.all', 'submissions.view.own'], 'legacy' => []],
        'followers.manage' => ['label' => 'Manage followers', 'description' => 'Add or remove followers on requests the person may manage.', 'group' => 'Collaboration', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['submissions.view.all', 'submissions.view.own'], 'legacy' => []],
        'reminders.send' => ['label' => 'Send reminders', 'description' => 'Send reminders on accessible requests.', 'group' => 'Collaboration', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['submissions.view.all', 'submissions.view.own'], 'legacy' => []],
        'approvals.act' => ['label' => 'Act on approvals', 'description' => 'Act when assigned to the current workflow step.', 'group' => 'Approvals', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'request_types.view' => ['label' => 'View request types', 'description' => 'View request-type configuration.', 'group' => 'Request types and workflows', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['templates.manage']],
        'request_types.manage' => ['label' => 'Manage request types', 'description' => 'Create and edit request-type drafts.', 'group' => 'Request types and workflows', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['request_types.view'], 'legacy' => ['templates.manage']],
        'request_types.publish' => ['label' => 'Publish request types', 'description' => 'Publish request-type versions.', 'group' => 'Request types and workflows', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['request_types.manage'], 'legacy' => ['templates.manage']],
        'workflows.view' => ['label' => 'View workflows', 'description' => 'View workflow templates.', 'group' => 'Request types and workflows', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['templates.manage']],
        'workflows.manage' => ['label' => 'Manage workflows', 'description' => 'Create and edit workflow drafts.', 'group' => 'Request types and workflows', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['workflows.view'], 'legacy' => ['templates.manage']],
        'workflows.publish' => ['label' => 'Publish workflows', 'description' => 'Publish workflow versions.', 'group' => 'Request types and workflows', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['workflows.manage'], 'legacy' => ['templates.manage']],
        'workflows.assign' => ['label' => 'Assign workflows', 'description' => 'Connect workflows to request types.', 'group' => 'Request types and workflows', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['workflows.view'], 'legacy' => ['templates.manage']],
        'reports.view' => ['label' => 'View reports', 'description' => 'Open analytics and reports.', 'group' => 'Reports and audit', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'reports.export' => ['label' => 'Export reports', 'description' => 'Download report data.', 'group' => 'Reports and audit', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['reports.view'], 'legacy' => []],
        'audit.view' => ['label' => 'View audit records', 'description' => 'View audit and evidence records.', 'group' => 'Reports and audit', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'audit.evidence.export' => ['label' => 'Export audit evidence', 'description' => 'Download evidence packs.', 'group' => 'Reports and audit', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['audit.view'], 'legacy' => []],
        'audit.auditor_access.manage' => ['label' => 'Manage auditor access', 'description' => 'Configure read-only auditor access.', 'group' => 'Reports and audit', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['audit.view'], 'legacy' => []],
        'branding.manage' => ['label' => 'Manage branding', 'description' => 'Configure application branding.', 'group' => 'Configuration', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'branding.custom_code.manage' => ['label' => 'Manage custom code', 'description' => 'Add analytics or custom code that runs in every signed-in session.', 'group' => 'Configuration', 'risk' => 'elevated', 'delegable' => false, 'depends_on_any' => ['branding.manage'], 'legacy' => []],
        'approval_settings.manage' => ['label' => 'Manage approval settings', 'description' => 'Configure approval behavior.', 'group' => 'Configuration', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'security_settings.manage' => ['label' => 'Manage security settings', 'description' => 'Configure installation security controls.', 'group' => 'Configuration', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'system_settings.manage' => ['label' => 'Manage system settings', 'description' => 'Configure mail, sessions, and social sign-in.', 'group' => 'Configuration', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'notification_channels.manage' => ['label' => 'Manage notification channels', 'description' => 'Configure notification delivery channels.', 'group' => 'Configuration', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'backups.view' => ['label' => 'View backups', 'description' => 'View backup status and snapshots.', 'group' => 'Backups', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'backups.configure' => ['label' => 'Configure backups', 'description' => 'Configure schedules and retention.', 'group' => 'Backups', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['backups.view'], 'legacy' => ['settings.manage']],
        'backups.create' => ['label' => 'Create backups', 'description' => 'Run a backup immediately.', 'group' => 'Backups', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['backups.view'], 'legacy' => ['settings.manage']],
        'backups.verify' => ['label' => 'Verify backups', 'description' => 'Run snapshot verification.', 'group' => 'Backups', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['backups.view'], 'legacy' => ['settings.manage']],
        'backups.download' => ['label' => 'Download backups', 'description' => 'Download local backup snapshots.', 'group' => 'Backups', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['backups.view'], 'legacy' => ['settings.manage']],
        'backups.destinations.manage' => ['label' => 'Manage backup destinations', 'description' => 'Configure off-site destinations.', 'group' => 'Backups', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['backups.view'], 'legacy' => ['settings.manage']],
        'backups.local_copies.remove' => ['label' => 'Remove local backup copies', 'description' => 'Remove local copies after safe replication.', 'group' => 'Backups', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['backups.view'], 'legacy' => ['settings.manage']],
        'backups.promote' => ['label' => 'Promote backups', 'description' => 'Restore or promote a selected snapshot.', 'group' => 'Backups', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['backups.verify'], 'legacy' => ['settings.manage']],
        'operations.view' => ['label' => 'View operations', 'description' => 'View scheduler and delivery health.', 'group' => 'Operations', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'operations.replay' => ['label' => 'Replay failed work', 'description' => 'Replay eligible failed deliveries.', 'group' => 'Operations', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['operations.view'], 'legacy' => ['settings.manage']],
        'operations.verify_copy' => ['label' => 'Verify application copy', 'description' => 'Run copy-integrity checks.', 'group' => 'Operations', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => ['operations.view'], 'legacy' => ['settings.manage']],
        'operations.reconcile' => ['label' => 'Reconcile operations', 'description' => 'Run safe operational reconciliation.', 'group' => 'Operations', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['operations.view'], 'legacy' => ['settings.manage']],
        'updates.view' => ['label' => 'View updates', 'description' => 'View available application updates.', 'group' => 'Operations', 'risk' => 'standard', 'delegable' => true, 'depends_on_any' => [], 'legacy' => ['settings.manage']],
        'updates.apply' => ['label' => 'Apply updates', 'description' => 'Apply an application update.', 'group' => 'Operations', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => ['updates.view'], 'legacy' => ['settings.manage']],
        'webhooks.manage' => ['label' => 'Manage webhooks', 'description' => 'Configure outgoing webhooks.', 'group' => 'Integrations', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
        'integrations.manage' => ['label' => 'Manage integrations', 'description' => 'Configure integration connectors.', 'group' => 'Integrations', 'risk' => 'elevated', 'delegable' => true, 'depends_on_any' => [], 'legacy' => []],
    ];

    /** @return list<array{key: string, label: string, description: string, group: string, risk: string, delegable: bool, depends_on_any: list<string>, legacy: list<string>}> */
    public static function all(): array
    {
        return array_map(fn (string $key, array $entry): array => ['key' => $key, ...$entry], array_keys(self::ENTRIES), self::ENTRIES);
    }

    /** @return array{key: string, label: string, description: string, group: string, risk: string, delegable: bool, depends_on_any: list<string>, legacy: list<string>}|null */
    public static function find(string $key): ?array
    {
        return isset(self::ENTRIES[$key]) ? ['key' => $key, ...self::ENTRIES[$key]] : null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ENTRIES);
    }

    /** @return list<string> */
    public static function legacyKeys(): array
    {
        return ['users.manage', 'templates.manage', 'settings.manage'];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::all() as $entry) {
            $groups[$entry['group']][] = $entry;
        }

        return $groups;
    }
}
