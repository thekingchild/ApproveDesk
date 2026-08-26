<?php

namespace Tests\Feature;

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

    public function test_organization_settings_article_explains_office_hours(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('help.show', 'organization-settings'))
            ->assertOk()
            ->assertSee('optional office hours')
            ->assertSee('do not enter 00:00–23:59')
            ->assertSee('life-ring on Approval settings')
            ->assertSee('Application logo')
            ->assertSee('Favicon')
            ->assertSee('Meta description')
            ->assertSee('Notification channels')
            ->assertSee('Security & privacy')
            ->assertSee('installation timezone')
            ->assertSee('Google or Microsoft')
            ->assertSee('Only the System owner can change this')
            ->assertDontSee('application timezone (UTC)')
            ->assertDontSee('APP_TIMEZONE')
            ->assertDontSee('.env');
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
            ->assertSee('installation timezone');
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
