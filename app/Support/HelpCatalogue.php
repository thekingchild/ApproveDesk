<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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
                summary: 'Public website versus signed-in home, search, notifications, and how Help stays out of the way.',
                icon: 'home',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'dashboard',
            ),
            new HelpArticle(
                slug: 'requests',
                title: 'Requests',
                summary: 'Create, draft, attach files, comment, withdraw, and revise a request.',
                icon: 'document-plus',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'submissions.index',
            ),
            new HelpArticle(
                slug: 'approvals',
                title: 'Approvals',
                summary: 'Use the workbench to preview, decide, delegate, escalate, or ask for information.',
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
                summary: 'Profile, security, passkeys, two-factor authentication, API tokens, and notification delivery.',
                icon: 'user-circle',
                group: 'Using ApproveDesk',
                audience: 'all',
                screenRoute: 'profile.edit',
            ),
            new HelpArticle(
                slug: 'users-and-roles',
                title: 'Users and roles',
                summary: 'Grant access, assign roles, import users from a spreadsheet, and deactivate accounts without locking out the last super admin.',
                icon: 'users',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.users.index',
            ),
            new HelpArticle(
                slug: 'departments',
                title: 'Departments',
                summary: 'Group people, assign managers, and use departments in routing.',
                icon: 'building-office',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.departments.index',
            ),
            new HelpArticle(
                slug: 'workflow-templates',
                title: 'Workflow templates',
                summary: 'Build approval chains, choose assignees, and publish frozen versions.',
                icon: 'arrows-right-left',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.workflow-templates.index',
            ),
            new HelpArticle(
                slug: 'request-types',
                title: 'Request types',
                summary: 'Design versioned forms, assign a workflow, and add selection rules for form values.',
                icon: 'document-text',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.request-types.index',
            ),
            new HelpArticle(
                slug: 'analytics',
                title: 'Analytics and export',
                summary: 'Read cycle time, bottlenecks, workload, and export a report.',
                icon: 'chart-bar',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.analytics.index',
            ),
            new HelpArticle(
                slug: 'webhooks',
                title: 'Webhooks',
                summary: 'Send signed workflow events to another system.',
                icon: 'rss',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.webhooks.index',
            ),
            new HelpArticle(
                slug: 'integrations',
                title: 'Integration hub',
                summary: 'Connect Teams, Slack, Drive, SharePoint, mail, or HTTP and run post-approval actions.',
                icon: 'puzzle-piece',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.integrations.index',
            ),
            new HelpArticle(
                slug: 'organization-settings',
                title: 'Organization settings',
                summary: 'Login branding, application logo and favicon, browser title and description, self-registration (activation mode and domain allow-list), email approval link lifetime, the business calendar, and optional notification channels.',
                icon: 'cog-6-tooth',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'settings.branding',
            ),
            new HelpArticle(
                slug: 'notification-channels',
                title: 'Notification channels',
                summary: 'Keep in-app and email on; optionally enable Teams, Slack, WhatsApp, and Telegram without putting tokens on personal settings.',
                icon: 'bell',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'settings.notification-channels',
            ),
            new HelpArticle(
                slug: 'audit-trail',
                title: 'Audit trail',
                summary: 'What is immutable, how evidence packs work, and how to read history.',
                icon: 'shield-check',
                group: 'Administration',
                audience: 'admin',
            ),
            new HelpArticle(
                slug: 'records',
                title: 'Records',
                summary: 'Auditor access, legal holds, retention, disposition, and pack verification.',
                icon: 'archive-box',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.records.index',
            ),
            new HelpArticle(
                slug: 'security-and-privacy',
                title: 'Security and privacy',
                summary: 'Sensitive fields, download stamps, devices, required second factor, rate limits, and account anonymization.',
                icon: 'lock-closed',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'settings.security-privacy',
            ),
            new HelpArticle(
                slug: 'operations',
                title: 'Operations',
                summary: 'Scheduled work, failed deliveries, stuck requests, and backup checks.',
                icon: 'signal',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.operations.index',
            ),
            new HelpArticle(
                slug: 'updates',
                title: 'Updates',
                summary: 'See this copy’s version and apply waiting database changes after a deploy.',
                icon: 'arrow-path',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.updates.index',
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
        $slugs = self::isAdministrator($user)
            ? ['definitions', 'users-and-roles', 'request-types', 'workflow-templates', 'analytics', 'requests', 'approvals']
            : ['definitions', 'requests', 'approvals', 'collaboration', 'account-settings'];

        return $this->visibleFor($user)
            ->filter(fn (HelpArticle $article): bool => in_array($article->slug, $slugs, true))
            ->sortBy(fn (HelpArticle $article): int => (int) array_search($article->slug, $slugs, true))
            ->values();
    }

    public static function isAdministrator(User $user): bool
    {
        if ($user->hasRole(['super-admin', 'admin'])) {
            return true;
        }

        try {
            foreach (['users.manage', 'departments.manage', 'templates.manage', 'settings.manage', 'reports.view', 'webhooks.manage', 'integrations.manage', 'audit.view'] as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }
        } catch (PermissionDoesNotExist) {
            return false;
        }

        return false;
    }
}
