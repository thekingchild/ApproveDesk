<?php

namespace Tests\Feature\Admin;

use App\Actions\Collaboration\SaveOutOfOfficeAction;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Exceptions\CollaborationException;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Collaboration\MentionParser;
use App\Services\Workflow\AssigneeDirectory;
use App\Services\Workflow\WorkflowAssigneeGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SystemOwnerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->owner = User::factory()->create([
            'name' => 'Hidden System Owner',
            'email' => 'hidden-owner@example.test',
        ])->assignRole('super-admin');
        SystemOwner::query()->create([
            'id' => SystemOwner::SINGLETON_ID,
            'user_id' => $this->owner->id,
        ]);
        $this->admin = User::factory()->create([
            'name' => 'Visible Administrator',
        ])->assignRole('admin');
    }

    public function test_owner_is_hidden_from_other_user_managers_but_visible_to_self(): void
    {
        Livewire::actingAs($this->admin)
            ->test('pages::admin.users')
            ->assertDontSee('hidden-owner@example.test')
            ->assertSee('Visible Administrator');

        Livewire::actingAs($this->owner)
            ->test('pages::admin.users')
            ->assertSee('hidden-owner@example.test');
    }

    public function test_owner_is_hidden_from_new_assignee_search_but_legacy_selection_still_resolves(): void
    {
        $directory = app(AssigneeDirectory::class);

        $this->assertNotContains($this->owner->id, $directory->search('user', 'Hidden')->pluck('id')->all());
        $this->assertContains($this->owner->id, $directory->selected('user', [$this->owner->id])->pluck('id')->all());
    }

    public function test_direct_owner_mentions_are_ignored_for_new_comments(): void
    {
        $ids = app(MentionParser::class)->userIds('@{'.$this->owner->id.'}', [$this->owner->id]);

        $this->assertSame([], $ids);
    }

    public function test_owner_cannot_be_submitted_as_a_new_out_of_office_fallback(): void
    {
        $this->expectException(CollaborationException::class);

        app(SaveOutOfOfficeAction::class)->execute(
            $this->admin,
            now()->addDay(),
            now()->addDays(2),
            $this->owner->id,
        );
    }

    public function test_owner_is_hidden_from_department_manager_options(): void
    {
        $component = Livewire::actingAs($this->admin)->test('pages::admin.departments');

        $this->assertNotContains($this->owner->id, $component->instance()->managerOptions()->pluck('id')->all());
    }

    public function test_owner_id_is_rejected_for_a_new_workflow_assignment(): void
    {
        $this->expectException(ValidationException::class);

        app(WorkflowAssigneeGuard::class)->assertAssignable([
            new WorkflowTemplateStepData(
                name: 'Owner step',
                stepType: StepType::Sequential,
                quorumCount: null,
                assigneeType: AssigneeType::User,
                assigneeIds: [$this->owner->id],
                allowDelegation: false,
            ),
        ]);
    }
}
