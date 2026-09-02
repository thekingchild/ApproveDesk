<?php

namespace Tests\Feature;

use App\Models\SystemOwner;
use App\Models\User;
use App\Support\HelpCatalogue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCentreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_help_page_requires_authentication(): void
    {
        $this->get(route('help'))
            ->assertRedirect(route('login'));
    }

    public function test_administrator_sees_compact_help_menu_and_admin_articles(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="help-menu"', false)
            ->assertSee('data-test="help-menu-users-and-roles"', false)
            ->assertSee('data-test="help-menu-request-types"', false)
            ->assertSee(__('Help'))
            ->assertDontSee('github.com/laravel/livewire-starter-kit', false);

        $this->actingAs($admin)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('Help centre')
            ->assertSee('Administration')
            ->assertSee('Users and roles')
            ->assertSee('Request types')
            ->assertSee('Feature definitions')
            ->assertSee('Looking for a screen?')
            ->assertDontSee('docs/kb')
            ->assertDontSee('implementation phase');
    }

    public function test_requester_does_not_see_administration_guides(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('Help centre')
            ->assertSee('Create a request')
            ->assertSee('Requests')
            ->assertDontSee('Users and roles')
            ->assertDontSee('Looking for a screen?')
            ->assertDontSee('docs/kb');

        $this->actingAs($submitter)
            ->get(route('help.show', 'users-and-roles'))
            ->assertForbidden();
    }

    public function test_custom_role_sees_only_help_for_its_actual_capabilities(): void
    {
        $backupViewer = User::factory()->create();
        $backupViewer->givePermissionTo('backups.view');

        $this->actingAs($backupViewer)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('Backups')
            ->assertDontSee('Users and roles')
            ->assertDontSee('Workflow templates')
            ->assertDontSee('Organization settings');

        $this->actingAs($backupViewer)
            ->get(route('help.show', 'backups'))
            ->assertOk();

        $this->actingAs($backupViewer)
            ->get(route('help.show', 'users-and-roles'))
            ->assertForbidden();
    }

    public function test_unrelated_administration_permission_does_not_unlock_all_admin_help(): void
    {
        $userViewer = User::factory()->create();
        $userViewer->givePermissionTo('users.view');

        $dashboard = $this->actingAs($userViewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="help-menu-users-and-roles"', false)
            ->assertDontSee('data-test="help-menu-request-types"', false);

        $this->assertStringNotContainsString('help-menu-backups', $dashboard->getContent());

        $this->actingAs($userViewer)
            ->get(route('help.show', 'backups'))
            ->assertForbidden();
    }

    public function test_article_does_not_offer_a_screen_the_reader_cannot_open(): void
    {
        $roleViewer = User::factory()->create();
        $roleViewer->givePermissionTo('roles.view');

        $this->actingAs($roleViewer)
            ->get(route('help.show', 'users-and-roles'))
            ->assertOk()
            ->assertSee('Users and roles')
            ->assertDontSee('Open this screen');
    }

    public function test_system_owner_help_is_owner_only_and_direct_access_fails_closed(): void
    {
        $owner = User::factory()->create();
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($owner)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('System owner controls');

        $this->actingAs($owner)
            ->get(route('help.show', 'system-owner-controls'))
            ->assertOk()
            ->assertSee('Account recovery')
            ->assertSee('Manage the theme catalogue')
            ->assertSee('Publish an announcement')
            ->assertSee('Analytics and custom code');

        $this->actingAs($admin)
            ->get(route('help'))
            ->assertOk()
            ->assertDontSee('System owner controls');

        $this->actingAs($admin)
            ->get(route('help.show', 'system-owner-controls'))
            ->assertForbidden()
            ->assertDontSee('Account recovery');
    }

    public function test_shared_help_does_not_publish_owner_only_procedures(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $catalogue = app(HelpCatalogue::class);

        foreach (['users-and-roles', 'organization-settings', 'announcements', 'application-themes'] as $slug) {
            $html = $catalogue->find($slug)?->html($admin) ?? '';

            foreach (['Invalidate password and email reset link', 'Analytics and custom code', 'Save announcement', 'Save custom theme'] as $ownerProcedure) {
                $this->assertStringNotContainsStringIgnoringCase($ownerProcedure, $html, $slug.' exposes '.$ownerProcedure);
            }
        }

        $this->assertStringNotContainsString(
            'href="/help/system-owner-controls"',
            $catalogue->find('users-and-roles')?->html($admin) ?? '',
        );
    }

    public function test_approval_settings_help_is_separate_from_branding_help(): void
    {
        $brandingManager = User::factory()->create();
        $brandingManager->givePermissionTo('branding.manage');
        $approvalManager = User::factory()->create();
        $approvalManager->givePermissionTo('approval_settings.manage');

        $this->actingAs($brandingManager)
            ->get(route('help.show', 'organization-settings'))
            ->assertOk()
            ->assertSee('Show public landing page')
            ->assertDontSee('Require sign-in and a second factor');

        $this->actingAs($brandingManager)
            ->get(route('help.show', 'approval-settings'))
            ->assertForbidden();

        $this->actingAs($approvalManager)
            ->get(route('help.show', 'approval-settings'))
            ->assertOk()
            ->assertSee('Require sign-in and a second factor')
            ->assertSee('Business calendar');

        $this->actingAs($approvalManager)
            ->get(route('help.show', 'organization-settings'))
            ->assertForbidden();
    }

    public function test_administrator_can_read_markdown_article(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'request-types'))
            ->assertOk()
            ->assertSee('Request types')
            ->assertSee('Open this screen')
            ->assertSee('Workflow assignment')
            ->assertSee('Document, Note, Memo, and Request are not')
            ->assertSee('Type a label for each field')
            ->assertDontSee('schema hash')
            ->assertDontSee('leave_reason')
            ->assertSee('data-test="help-article-body"', false);
    }

    public function test_unknown_article_returns_not_found(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('help.show', 'not-a-real-guide'))
            ->assertNotFound();
    }

    public function test_admin_users_screen_has_contextual_help_link(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('data-test="contextual-help"', false)
            ->assertSee(route('help.show', 'users-and-roles'), false);
    }

    public function test_definitions_article_explains_core_features(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('help.show', 'definitions'))
            ->assertOk()
            ->assertSee('A single case waiting for a decision')
            ->assertSee('A named form')
            ->assertSee('A named approval chain')
            ->assertSee('The link from a request type')
            ->assertSee('The canvas and list editor')
            ->assertSee('One frozen copy of a workflow template')
            ->assertSee('An ordered rule on a request type')
            ->assertSee('Working days, office hours, and holidays')
            ->assertSee('The checks that run before a workflow version is published')
            ->assertSee('The search field on a workflow step')
            ->assertSee('Someone granted explicit view access')
            ->assertSee('A named person in a comment')
            ->assertSee('A dated absence on your account')
            ->assertSee('A copy of a draft kept on this device')
            ->assertSee('The email link or Teams/Slack')
            ->assertSee('A signed download of one request')
            ->assertSee('A time-bounded, read-only grant')
            ->assertSee('A form answer hidden from people')
            ->assertSee('A zip of your profile');
    }

    public function test_announcement_and_application_theme_help_is_available_to_signed_in_people(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('help.show', 'announcements'))
            ->assertOk()
            ->assertSee('Sticky')
            ->assertSee('Mark as read')
            ->assertSee('Show once')
            ->assertSee('System owner');

        $this->actingAs($user)
            ->get(route('help.show', 'application-themes'))
            ->assertOk()
            ->assertSee('Organization default')
            ->assertSee('Light, Dark, or System')
            ->assertSee('4.5:1')
            ->assertSee('Built-in palettes cannot be archived');
    }

    public function test_collaboration_article_explains_followers_and_ooo(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('help.show', 'collaboration'))
            ->assertOk()
            ->assertSee('Adding a person grants them view access')
            ->assertSee('15 minutes')
            ->assertSee('quiet hours')
            ->assertSee('fallback approver')
            ->assertSee('estimated due time');
    }

    public function test_requests_article_explains_offline_drafts(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('help.show', 'requests'))
            ->assertOk()
            ->assertSee('Keep this device')
            ->assertSee('Keep saved draft')
            ->assertSee('live connection');
    }

    public function test_approvals_article_explains_offline_decisions_and_stale_links(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('help.show', 'approvals'))
            ->assertOk()
            ->assertSee('disable while you are offline')
            ->assertSee('tampered links');
    }

    public function test_workflow_templates_article_explains_builder_and_versions(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'workflow-templates'))
            ->assertOk()
            ->assertSee('canvas and list views of the same chain')
            ->assertSee('read-only simulation')
            ->assertSee('Notification')
            ->assertSee('cannot change their steps')
            ->assertSee('Requests that already started keep the')
            ->assertSee('type at least two characters to search')
            ->assertSee('Business hours use organization working days, office hours, and holidays');
    }

    public function test_branding_article_explains_public_access_without_other_admin_controls(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'organization-settings'))
            ->assertOk()
            ->assertSee('Application logo')
            ->assertSee('Favicon')
            ->assertSee('Meta description')
            ->assertSee('Show public landing page')
            ->assertSee('Allow self-registration')
            ->assertSee('Google or Microsoft')
            ->assertDontSee('optional office hours')
            ->assertDontSee('Analytics and custom code')
            ->assertDontSee('APP_TIMEZONE')
            ->assertDontSee('.env');
    }

    public function test_users_and_roles_article_omits_protected_owner_procedures(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'users-and-roles'))
            ->assertOk()
            ->assertSee('Protected procedures are kept in System owner controls')
            ->assertDontSee('Invalidate password and email reset link')
            ->assertDontSee('analytics and custom code');
    }

    public function test_notification_channels_article_explains_optional_delivery(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'notification-channels'))
            ->assertOk()
            ->assertSee('In-app')
            ->assertSee('In-app only')
            ->assertSee('Settings → Notification channels')
            ->assertSee('{{approval_url}}')
            ->assertSee('approved template')
            ->assertSee('does not keep an always-on chat connection');
    }

    public function test_notification_channels_article_includes_whatsapp_templates_and_mappings_for_every_kind(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $response = $this->actingAs($admin)
            ->get(route('help.show', 'notification-channels'))
            ->assertOk()
            ->assertSee('WhatsApp template text is separate from email copy')
            ->assertSee('Create the wording in WhatsApp Manager')
            ->assertSee('Enter binding lines in the same order as the placeholders')
            ->assertSee('recipient_name=1')
            ->assertSee('approval_url=5')
            ->assertSee('count=2');

        foreach (['Action required', 'Decision', 'Reminder', 'Mention', 'Comment', 'Follower update', 'Delegation', 'Digest'] as $kind) {
            $response->assertSee($kind);
        }
    }

    public function test_records_article_explains_auditor_access_and_holds(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'records'))
            ->assertOk()
            ->assertSee('Auditor access')
            ->assertSee('Legal holds')
            ->assertSee('Retention and disposition');
    }

    public function test_security_and_privacy_article_explains_devices_and_anonymize(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'security-and-privacy'))
            ->assertOk()
            ->assertSee('Sensitive fields')
            ->assertSee('Download my data')
            ->assertSee('Anonymize an account')
            ->assertSee('Sign out other devices')
            ->assertSee('Require a second factor')
            ->assertSee('Require a Turnstile check');
    }

    public function test_operations_article_explains_replay_and_backup_check(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'operations'))
            ->assertOk()
            ->assertSee('Failed deliveries')
            ->assertSee('Stuck requests')
            ->assertSee('Backup check')
            ->assertSee('Check this copy');
    }

    public function test_backups_article_explains_separate_kinds_and_promote(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'backups'))
            ->assertOk()
            ->assertSee('Backup database')
            ->assertSee('Backup files')
            ->assertSee('Promote')
            ->assertSee('Check this copy')
            ->assertSee('Save Drive destination')
            ->assertSee('Refresh token')
            ->assertSee('https://console.cloud.google.com/', false)
            ->assertSee('https://developers.google.com/oauthplayground', false)
            ->assertSee('installation timezone')
            ->assertSee('still the same one that was taken')
            ->assertSee('Extra or altered files');
    }

    public function test_webhooks_article_requires_public_https(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'webhooks'))
            ->assertOk()
            ->assertSee('public HTTPS')
            ->assertSee('your own network');
    }

    public function test_integrations_article_requires_public_https_and_hides_response_bodies(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'integrations'))
            ->assertOk()
            ->assertSee('public HTTPS')
            ->assertSee('not the response body');
    }

    public function test_updates_article_explains_apply_and_backup_reminder(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'updates'))
            ->assertOk()
            ->assertSee('Apply database updates')
            ->assertSee('Check updates')
            ->assertSee('Take a host backup first');
    }

    public function test_catalogue_matches_markdown_files(): void
    {
        $catalogue = app(HelpCatalogue::class);

        foreach ($catalogue->articles() as $article) {
            $this->assertFileExists($article->path(), $article->slug.' is missing its Markdown source');
            $this->assertNotSame('', trim($article->html()));
        }
    }

    public function test_help_markdown_links_open_in_app_articles(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('help.show', 'definitions'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('help.show', 'getting-around'), $html);
        $this->assertStringContainsString(route('help.show', 'requests'), $html);
        $this->assertStringNotContainsString('getting-around.md', $html);
        $this->assertStringNotContainsString('href="requests.md"', $html);
    }

    public function test_published_help_does_not_expose_architecture(): void
    {
        $catalogue = app(HelpCatalogue::class);

        foreach ($catalogue->articles() as $article) {
            $html = $article->html();

            $this->assertDoesNotMatchRegularExpression(
                '/href="[^"]+\.md"/',
                $html,
                $article->slug.' still links to a raw .md file',
            );

            foreach (['Livewire', 'docs/kb', 'IMPLEMENTATION_PLAN', 'schema hash', 'long-polling', 'Phase 6A', 'leave_reason', 'APP_TIMEZONE', '.env'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $leak,
                    $html,
                    $article->slug.' exposes "'.$leak.'"',
                );
            }
        }
    }

    public function test_requester_definitions_page_has_no_dead_or_forbidden_links(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $html = app(HelpCatalogue::class)->find('definitions')->html($submitter);

        preg_match_all('/href="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $href) {
            $path = parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_PATH) ?: $href;

            $this->actingAs($submitter)->get($path)->assertOk($href.' must be readable by a requester');
        }
    }

    public function test_administrator_definitions_page_has_no_dead_links(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $html = app(HelpCatalogue::class)->find('definitions')->html($admin);

        preg_match_all('/href="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $href) {
            $path = parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_PATH) ?: $href;

            $this->actingAs($admin)->get($path)->assertOk($href.' must be readable by an administrator');
        }
    }
}
