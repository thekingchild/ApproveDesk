<?php

namespace Tests\Feature\Settings;

use App\Actions\Settings\UpdateApprovalSettingsAction;
use App\Enums\TimeoutCalendar;
use App\Enums\WorkflowStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalSettingsWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_saves_office_hours_and_preserves_by_day(): void
    {
        Setting::put('calendar.working_hours', json_encode([
            'start' => '08:00',
            'end' => '16:00',
            'by_day' => ['5' => ['start' => '09:00', 'end' => '13:00']],
        ]));
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('workingHoursStart', '09:00')
            ->set('workingHoursEnd', '17:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['start' => '09:00', 'end' => '17:00', 'by_day' => ['5' => ['start' => '09:00', 'end' => '13:00']]],
            json_decode((string) Setting::get('calendar.working_hours'), true),
        );
    }

    public function test_posting_seconds_is_normalized(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('workingHoursStart', '09:00:00')
            ->set('workingHoursEnd', '17:00:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['start' => '09:00', 'end' => '17:00'],
            json_decode((string) Setting::get('calendar.working_hours'), true),
        );
    }

    public function test_clearing_office_hours_restores_twenty_four_hour_mode(): void
    {
        Setting::put('calendar.working_hours', json_encode(['start' => '09:00', 'end' => '17:00']));
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('workingHoursStart', '')
            ->set('workingHoursEnd', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Setting::get('calendar.working_hours'));
    }

    public function test_start_must_be_before_end(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('workingHoursStart', '17:00')
            ->set('workingHoursEnd', '09:00')
            ->call('save')
            ->assertHasErrors(['workingHoursEnd']);

        $this->assertNull(Setting::get('calendar.working_hours'));
    }

    public function test_one_field_alone_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('workingHoursStart', '09:00')
            ->set('workingHoursEnd', '')
            ->call('save')
            ->assertHasErrors(['workingHoursEnd']);
    }

    public function test_unauthorized_user_cannot_open_approval_settings(): void
    {
        $user = User::factory()->create()->assignRole('approver');

        $this->actingAs($user)
            ->get(route('settings.approvals'))
            ->assertForbidden();
    }

    public function test_saving_hours_moves_business_deadline_not_elapsed(): void
    {
        Carbon::setTestNow('2026-12-21 16:00:00');
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create();
        $businessTemplate = WorkflowTemplate::factory()->sequential([$approver])->create();
        $elapsedTemplate = WorkflowTemplate::factory()->sequential([$approver])->create();
        $businessStep = $businessTemplate->publishedVersion->steps->first();
        $elapsedStep = $elapsedTemplate->publishedVersion->steps->first();
        $businessStep->updateQuietly([
            'timeout_hours' => 8,
            'timeout_calendar' => TimeoutCalendar::Business->value,
        ]);
        $elapsedStep->updateQuietly([
            'timeout_hours' => 8,
            'timeout_calendar' => TimeoutCalendar::Elapsed->value,
        ]);

        $business = WorkflowInstance::factory()->create([
            'template_id' => $businessTemplate->id,
            'template_version_id' => $businessTemplate->publishedVersion->id,
            'current_step_id' => $businessStep->id,
            'status' => WorkflowStatus::Active,
            'started_at' => '2026-12-21 16:00:00',
            'step_started_at' => '2026-12-21 16:00:00',
        ]);
        $elapsed = WorkflowInstance::factory()->create([
            'template_id' => $elapsedTemplate->id,
            'template_version_id' => $elapsedTemplate->publishedVersion->id,
            'current_step_id' => $elapsedStep->id,
            'status' => WorkflowStatus::Active,
            'started_at' => '2026-12-21 16:00:00',
            'step_started_at' => '2026-12-21 16:00:00',
        ]);

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('workingHoursStart', '09:00')
            ->set('workingHoursEnd', '17:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-12-22 16:00:00', $business->fresh(['currentStep'])->deadlineAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 00:00:00', $elapsed->fresh(['currentStep'])->deadlineAt()?->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_lifetime_only_save_does_not_wipe_hours(): void
    {
        Setting::put('calendar.working_hours', json_encode(['start' => '09:00', 'end' => '17:00']));
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('emailLinkExpirationHours', 24)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('24', Setting::get('approvals.email_link_expiration_hours'));
        $this->assertSame(
            ['start' => '09:00', 'end' => '17:00'],
            json_decode((string) Setting::get('calendar.working_hours'), true),
        );
    }

    public function test_three_argument_execute_does_not_wipe_hours(): void
    {
        Setting::put('calendar.working_hours', json_encode(['start' => '09:00', 'end' => '17:00']));

        app(UpdateApprovalSettingsAction::class)->execute(48, [1, 2, 3, 4, 5], '');

        $this->assertSame('48', Setting::get('approvals.email_link_expiration_hours'));
        $this->assertSame(
            ['start' => '09:00', 'end' => '17:00'],
            json_decode((string) Setting::get('calendar.working_hours'), true),
        );
    }

    public function test_office_hours_copy_uses_the_configured_timezone(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->assertSee('Africa/Lagos');
    }
}
