<?php

namespace Tests\Feature\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Department;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubmissionSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_view_filters_by_search_type_submitter_department_and_date(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $finance = Department::factory()->create(['name' => 'Finance']);
        $operations = Department::factory()->create(['name' => 'Operations']);
        $alice = User::factory()->create(['name' => 'Alice', 'department_id' => $finance->id]);
        $bob = User::factory()->create(['name' => 'Bob', 'department_id' => $operations->id]);
        $matching = Submission::factory()->for($alice, 'submitter')->create([
            'title' => 'Annual procurement memo',
            'type' => 'memo',
            'department_id' => $finance->id,
            'status' => SubmissionStatus::InReview,
            'created_at' => '2026-08-10 09:00:00',
        ]);
        $other = Submission::factory()->for($bob, 'submitter')->create([
            'title' => 'Office access request',
            'type' => 'request',
            'department_id' => $operations->id,
            'status' => SubmissionStatus::Approved,
            'created_at' => '2026-07-01 09:00:00',
        ]);

        Livewire::actingAs($admin)
            ->test('pages::submissions.index')
            ->set('search', 'procurement')
            ->set('typeFilter', 'memo')
            ->set('statusFilter', 'in_review')
            ->set('submitterFilter', (string) $alice->id)
            ->set('departmentFilter', (string) $finance->id)
            ->set('dateFrom', '2026-08-01')
            ->set('dateTo', '2026-08-31')
            ->assertSee($matching->reference_number)
            ->assertDontSee($other->reference_number);
    }

    public function test_personal_view_cannot_expand_scope_with_organization_filters(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $owner = User::factory()->create()->assignRole('submitter');
        $other = User::factory()->create()->assignRole('submitter');
        $owned = Submission::factory()->for($owner, 'submitter')->create(['title' => 'Owned memo']);
        $hidden = Submission::factory()->for($other, 'submitter')->create(['title' => 'Hidden memo']);

        Livewire::actingAs($owner)
            ->test('pages::submissions.index')
            ->set('submitterFilter', (string) $other->id)
            ->assertSee($owned->reference_number)
            ->assertDontSee($hidden->reference_number);
    }
}
