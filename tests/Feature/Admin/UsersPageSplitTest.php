<?php

namespace Tests\Feature\Admin;

use App\Enums\DisplayNameChangeStatus;
use App\Models\DisplayNameChange;
use App\Models\SystemOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR 0006 split of the Users page, and the RV-ARC-08 fixes that came with it. */
class UsersPageSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create(['name' => 'Hidden Owner'])->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $this->owner->id]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_rv_arc_08_the_hidden_owner_pending_name_is_not_shown_to_other_administrators(): void
    {
        $this->pendingName($this->owner, 'Secret Owner Name');
        $this->pendingName(User::factory()->create(), 'Visible Request');

        Livewire::actingAs($this->admin)->test('admin.users.name-changes')
            ->assertSee('Visible Request')
            ->assertDontSee('Secret Owner Name');

        Livewire::actingAs($this->owner)->test('admin.users.name-changes')
            ->assertSee('Secret Owner Name');
    }

    public function test_rv_arc_08_the_line_manager_lookup_ignores_the_owner_account(): void
    {
        $member = User::factory()->create();

        Livewire::actingAs($this->admin)->test('admin.users.user-form')
            ->call('edit', $member->id)
            ->set('managerId', (string) $this->owner->id)
            ->assertDontSee('Hidden Owner')
            ->call('save')
            ->assertHasErrors(['managerId']);
    }

    public function test_rejecting_a_display_name_asks_for_a_reason_on_the_page(): void
    {
        $change = $this->pendingName(User::factory()->create(), 'New Name');

        Livewire::actingAs($this->admin)->test('admin.users.name-changes')
            ->call('startReject', $change->id)
            ->assertSeeHtml('data-test="reject-name-form"')
            ->call('reject')
            ->assertHasErrors(['rejectionReason'])
            ->set('rejectionReason', 'Not verified.')
            ->call('reject')
            ->assertHasNoErrors();

        $this->assertSame(DisplayNameChangeStatus::Rejected, $change->fresh()->status);
    }

    public function test_the_page_opens_the_form_and_refreshes_after_a_save(): void
    {
        $member = User::factory()->create(['name' => 'Ada Member'])->assignRole('approver');

        Livewire::actingAs($this->admin)->test('pages::admin.users')
            ->assertSee('Ada Member')
            ->call('createUser')
            ->assertDispatched('open-user-form')
            ->call('editUser', $member->id)
            ->assertDispatched('open-user-form', userId: $member->id);

        Livewire::actingAs($this->admin)->test('admin.users.user-form')
            ->call('edit', $member->id)
            ->set('name', 'Ada Renamed')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('users-changed');
    }

    private function pendingName(User $user, string $name): DisplayNameChange
    {
        return DisplayNameChange::query()->create([
            'user_id' => $user->id,
            'pending_user_id' => $user->id,
            'proposed_name' => $name,
            'normalized_name' => mb_strtolower($name),
            'status' => DisplayNameChangeStatus::Pending,
        ]);
    }
}
