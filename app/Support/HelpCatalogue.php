<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

final class HelpCatalogue
{
    /**
     * @return Collection<int, HelpArticle>
     */
    public function articles(): Collection
    {
        return collect([
            new HelpArticle(
                slug: 'definitions',
                title: 'Feature definitions',
                summary: 'What request, request type, workflow template, assignment, and status mean.',
                icon: 'book-open',
                group: 'Using ApproveDesk',
                audience: 'all',
            ),
            new HelpArticle(
                slug: 'getting-around',
                title: 'Getting around',
                summary: 'Login-first site address, optional public website, signed-in home, collapsible navigation, search, notifications, and Help.',
                icon: 'home',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'dashboard',
            ),
            new HelpArticle(
                slug: 'requests',
                title: 'Requests',
                summary: 'Create, draft, attach files, comment, withdraw, revise, and print or download an approval report.',
                icon: 'document-plus',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'submissions.index',
            ),
            new HelpArticle(
                slug: 'approvals',
                title: 'Approvals',
                summary: 'Use the workbench to preview, decide, delegate, escalate, ask for information, or open an approval report.',
                icon: 'check-circle',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'approvals.inbox',
            ),
            new HelpArticle(
                slug: 'collaboration',
                title: 'Collaboration',
                summary: 'Follow a request, mention people, send reminders, set out of office, and read the next step.',
                icon: 'chat-bubble-left-right',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'submissions.index',
            ),
            new HelpArticle(
                slug: 'statuses',
                title: 'Request statuses',
                summary: 'What each status means and who can act on it.',
                icon: 'queue-list',
                group: 'Using ApproveDesk',
                audience: 'all',
            ),
            new HelpArticle(
                slug: 'account-settings',
                title: 'Your account',
                summary: 'Profile, appearance and application theme, security, passkeys, two-factor authentication, API tokens, and notification delivery.',
                icon: 'user-circle',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'profile.edit',
            ),
            new HelpArticle(
                slug: 'announcements',
                title: 'Announcements',
                summary: 'Read compact Dashboard-only sticky, acknowledge, and one-time messages; System owners can format and publish them by audience.',
                icon: 'megaphone',
                group: 'Using ApproveDesk',
                audience: 'all',
            ),
            new HelpArticle(
                slug: 'application-themes',
                title: 'Application themes',
                summary: 'Choose an enabled palette independently from Light, Dark, or System mode; System owners curate the catalogue.',
                icon: 'swatch',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'appearance.edit',
            ),
            new HelpArticle(
                slug: 'users-and-roles',
                title: 'Users and roles',
                summary: 'Grant access, assign roles, import users from a spreadsheet, and deactivate accounts without locking out the last super admin.',
                icon: 'users',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['users.view', 'roles.view'],
                screenRoute: 'admin.users.index',
                screenPermission: 'users.view',
            ),
            new HelpArticle(
                slug: 'departments',
                title: 'Departments',
                summary: 'Group people, assign managers, and use departments in routing.',
                icon: 'building-office',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['departments.view'],
                screenRoute: 'admin.departments.index',
                screenPermission: 'departments.view',
            ),
            new HelpArticle(
                slug: 'workflow-templates',
                title: 'Workflow templates',
                summary: 'Build approval chains, choose assignees, and publish frozen versions.',
                icon: 'arrows-right-left',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['workflows.view'],
                screenRoute: 'admin.workflow-templates.index',
                screenPermission: 'workflows.view',
            ),
            new HelpArticle(
                slug: 'request-types',
                title: 'Request types',
                summary: 'Design versioned forms, assign a workflow, and add selection rules for form values.',
                icon: 'document-text',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['request_types.view'],
                screenRoute: 'admin.request-types.index',
                screenPermission: 'request_types.view',
            ),
            new HelpArticle(
                slug: 'analytics',
                title: 'Analytics and export',
                summary: 'Read cycle time, bottlenecks, workload, and export a report.',
                icon: 'chart-bar',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['reports.view'],
                screenRoute: 'admin.analytics.index',
                screenPermission: 'reports.view',
            ),
            new HelpArticle(
                slug: 'webhooks',
                title: 'Webhooks',
                summary: 'Send signed workflow events to another system. Destinations must be public HTTPS.',
                icon: 'rss',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['webhooks.manage'],
                screenRoute: 'admin.webhooks.index',
                screenPermission: 'webhooks.manage',
            ),
            new HelpArticle(
                slug: 'integrations',
                title: 'Integration hub',
                summary: 'Connect Teams, Slack, Drive, SharePoint, mail, or HTTP and run post-approval actions.',
                icon: 'puzzle-piece',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['integrations.manage'],
                screenRoute: 'admin.integrations.index',
                screenPermission: 'integrations.manage',
            ),
            new HelpArticle(
                slug: 'organization-settings',
                title: 'Branding and public access',
                summary: 'Company identity, printable branding, login appearance, optional public landing page, and self-registration.',
                icon: 'paint-brush',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['branding.manage'],
                screenRoute: 'settings.branding',
                screenPermission: 'branding.manage',
            ),
            new HelpArticle(
                slug: 'approval-settings',
                title: 'Approval settings',
                summary: 'Owner-controlled approval IDs, printable status stamps, signed-link identity checks, calendars, and quiet hours.',
                icon: 'clock',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['approval_settings.manage'],
                screenRoute: 'settings.approvals',
                screenPermission: 'approval_settings.manage',
            ),
            new HelpArticle(
                slug: 'notification-channels',
                title: 'Notification channels',
                summary: 'Keep in-app and email on; optionally enable Teams, Slack, WhatsApp, and Telegram without putting tokens on personal settings.',
                icon: 'bell',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['notification_channels.manage'],
                screenRoute: 'settings.notification-channels',
                screenPermission: 'notification_channels.manage',
            ),
            new HelpArticle(
                slug: 'audit-trail',
                title: 'Audit trail',
                summary: 'What is immutable, how evidence packs work, and how to read history.',
                icon: 'shield-check',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['audit.view'],
            ),
            new HelpArticle(
                slug: 'records',
                title: 'Records',
                summary: 'Auditor access, legal holds, retention, disposition, and pack verification.',
                icon: 'archive-box',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['audit.view'],
                screenRoute: 'admin.records.index',
                screenPermission: 'settings.manage',
            ),
            new HelpArticle(
                slug: 'security-and-privacy',
                title: 'Security and privacy',
                summary: 'Sensitive fields, download stamps, devices, required second factor, bot protection, rate limits, and account anonymization.',
                icon: 'lock-closed',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['security_settings.manage'],
                screenRoute: 'settings.security-privacy',
                screenPermission: 'security_settings.manage',
            ),
            new HelpArticle(
                slug: 'operations',
                title: 'Operations',
                summary: 'Scheduled work, failed deliveries, stuck requests, and backup checks.',
                icon: 'signal',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['operations.view'],
                screenRoute: 'admin.operations.index',
                screenPermission: 'operations.view',
            ),
            new HelpArticle(
                slug: 'backups',
                title: 'Backups',
                summary: 'Separate database and file snapshots, destinations, and promote after a check that the copy is still the one that was taken.',
                icon: 'circle-stack',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['backups.view'],
                screenRoute: 'admin.backups.index',
                screenPermission: 'backups.view',
            ),
            new HelpArticle(
                slug: 'updates',
                title: 'Updates',
                summary: 'See this copy’s version and apply waiting database changes after a deploy.',
                icon: 'arrow-path',
                group: 'Administration',
                audience: 'permission',
                requiredPermissions: ['updates.view'],
                screenRoute: 'admin.updates.index',
                screenPermission: 'updates.view',
            ),
            new HelpArticle(
                slug: 'system-owner-controls',
                title: 'System owner controls',
                summary: 'Protected account recovery, administrator grants, capacity, announcements, themes, and custom code.',
                icon: 'key',
                group: 'System owner',
                audience: 'owner',
            ),
        ]);
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    public function visibleFor(User $user): Collection
    {
        return $this->articles()
            ->filter(fn (HelpArticle $article): bool => $article->visibleTo($user))
            ->values();
    }

    public function find(string $slug): ?HelpArticle
    {
        return $this->articles()->first(
            fn (HelpArticle $article): bool => $article->slug === $slug,
        );
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    public function menuArticles(User $user): Collection
    {
        $visible = $this->visibleFor($user);
        $administration = $visible
            ->filter(fn (HelpArticle $article): bool => $article->group === 'Administration')
            ->take(5)
            ->pluck('slug')
            ->all();
        $owner = $user->isSystemOwner() ? ['system-owner-controls'] : [];
        $slugs = array_values(array_unique([
            'definitions',
            ...$owner,
            ...$administration,
            'requests',
            'approvals',
            'collaboration',
            'account-settings',
        ]));

        return $visible
            ->filter(fn (HelpArticle $article): bool => in_array($article->slug, $slugs, true))
            ->sortBy(fn (HelpArticle $article): int => (int) array_search($article->slug, $slugs, true))
            ->values();
    }

    public function hasAdministrationHelp(User $user): bool
    {
        return $this->visibleFor($user)
            ->contains(fn (HelpArticle $article): bool => $article->group === 'Administration');
    }
}
