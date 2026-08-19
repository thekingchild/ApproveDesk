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
                slug: 'getting-around',
                title: 'Getting around',
                summary: 'Home, search, notifications, and how Help stays out of the way.',
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
                summary: 'Grant access, assign roles, and deactivate accounts without locking out the last super admin.',
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
                summary: 'Build sequential, parallel, quorum, conditional, and automatic approval chains.',
                icon: 'arrows-right-left',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'admin.workflow-templates.index',
            ),
            new HelpArticle(
                slug: 'request-types',
                title: 'Request types',
                summary: 'Design versioned forms, publish them, and keep old submissions on their original schema.',
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
                slug: 'organization-settings',
                title: 'Organization settings',
                summary: 'Login branding, registration, and email approval link lifetime.',
                icon: 'cog-6-tooth',
                group: 'Administration',
                audience: 'admin',
                screenRoute: 'settings.branding',
            ),
            new HelpArticle(
                slug: 'audit-trail',
                title: 'Audit trail',
                summary: 'What is immutable, what published forms lock, and how to read history.',
                icon: 'shield-check',
                group: 'Administration',
                audience: 'admin',
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
            ? ['users-and-roles', 'request-types', 'workflow-templates', 'analytics', 'requests', 'approvals']
            : ['requests', 'approvals', 'account-settings'];

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
            foreach (['users.manage', 'departments.manage', 'templates.manage', 'settings.manage', 'reports.view', 'webhooks.manage'] as $permission) {
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
