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
            ->assertSee('Keeping this guide current');
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
            ->assertDontSee('Keeping this guide current');

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
            ->assertSee('schema hash')
            ->assertSee('Workflow assignment')
            ->assertSee('Document, Note, Memo, and Request are not')
            ->assertSee('The builder asks for a label, not a machine name')
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
            ->assertSee('One frozen copy of a workflow template');
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
            ->assertSee('cannot change their steps');
    }

    public function test_catalogue_matches_markdown_files(): void
    {
        $catalogue = app(HelpCatalogue::class);

        foreach ($catalogue->articles() as $article) {
            $this->assertFileExists($article->path(), $article->slug.' is missing its Markdown source');
            $this->assertNotSame('', trim($article->html()));
        }
    }
}
