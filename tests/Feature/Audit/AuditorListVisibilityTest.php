<?php

namespace Tests\Feature\Audit;

use App\Enums\SystemRole;
use App\Models\AuditorAccess;
use App\Models\Submission;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** RV-ARC-16: the request list and dashboard follow the same visibility rule as the request page. */
class AuditorListVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->auditor = User::factory()->create()->assignRole(SystemRole::Auditor->value);
        Submission::factory()->count(3)->create();
    }

    public function test_an_auditor_with_active_access_sees_every_request_in_the_list_and_dashboard(): void
    {
        $this->grant(now()->subDay(), now()->addDay());

        Livewire::actingAs($this->auditor)->test('pages::submissions.index')
            ->assertSet('organizationView', true);
        $dashboard = app(ReportService::class)->dashboard($this->auditor);
        $this->assertSame(3, $dashboard['recent']->count());

        $this->actingAs($this->auditor)->get(route('submissions.index'))->assertOk()->assertSee(__('All submissions'));
    }

    public function test_an_expired_grant_falls_back_to_the_auditors_own_requests(): void
    {
        $this->grant(now()->subDays(3), now()->subDay());

        Livewire::actingAs($this->auditor)->test('pages::submissions.index')
            ->assertSet('organizationView', false);
        $this->assertSame(0, app(ReportService::class)->dashboard($this->auditor)['recent']->count());
    }

    private function grant(\DateTimeInterface $starts, \DateTimeInterface $ends): void
    {
        AuditorAccess::query()->create([
            'user_id' => $this->auditor->id,
            'granted_by' => User::factory()->create()->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'reason' => 'Annual audit',
        ]);
    }
}
