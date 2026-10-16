<?php

namespace Tests\Feature\Users;

use App\Actions\Collaboration\FollowSubmissionAction;
use App\Actions\Privacy\AnonymizeUserAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\SiteLimit;
use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\Users\UserImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for review findings RV-SEC-22 (anonymizing leaves the
 * photo, follows, and open delegations behind), RV-BUG-24 (import "update
 * existing" skipped row validation) and RV-BUG-25 (import silently dropped a
 * manager whose own row failed).
 */
class UserPrivacyAndImportFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
    }

    private function submitted(): Submission
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh();
    }

    public function test_anonymizing_deletes_the_profile_photo(): void
    {
        Storage::disk('local')->put('profile-images/x.png', 'face');
        $target = User::factory()->create(['profile_image_path' => 'profile-images/x.png', 'profile_image_mime' => 'image/png']);
        $target->assignRole('submitter');

        app(AnonymizeUserAction::class)->execute($target, $this->admin);

        $target->refresh();
        $this->assertTrue($target->isAnonymized());
        $this->assertNull($target->profile_image_path);
        $this->assertNull($target->profile_image_mime);
        Storage::disk('local')->assertMissing('profile-images/x.png');
        $this->actingAs($this->admin)->get(route('profile-image.show', $target))->assertNotFound();
    }

    public function test_anonymizing_removes_the_persons_follows_but_not_others(): void
    {
        $submission = $this->submitted();
        $target = User::factory()->create()->assignRole('approver');
        $other = User::factory()->create()->assignRole('approver');
        app(FollowSubmissionAction::class)->execute($submission, $this->submitter, $target);
        app(FollowSubmissionAction::class)->execute($submission, $this->submitter, $other);

        app(AnonymizeUserAction::class)->execute($target, $this->admin);

        $this->assertFalse(SubmissionWatcher::query()->where('user_id', $target->id)->exists());
        $this->assertTrue(SubmissionWatcher::query()->where('user_id', $other->id)->exists());
    }

    public function test_anonymizing_ends_current_and_future_delegations_and_keeps_past_ones(): void
    {
        $instance = $this->submitted()->workflowInstance()->firstOrFail();
        $target = User::factory()->create()->assignRole('approver');
        $colleague = User::factory()->create()->assignRole('approver');

        $active = Delegation::factory()->create([
            'instance_id' => $instance->id, 'delegator_id' => $target->id, 'delegate_id' => $colleague->id,
            'starts_at' => now()->subHour(), 'expires_at' => now()->addDay(),
        ]);
        $future = Delegation::factory()->create([
            'instance_id' => $instance->id, 'delegator_id' => $colleague->id, 'delegate_id' => $target->id,
            'starts_at' => now()->addDays(2), 'expires_at' => now()->addDays(5),
        ]);
        $pastEnd = now()->subDay()->startOfSecond();
        $past = Delegation::factory()->create([
            'instance_id' => $instance->id, 'delegator_id' => $target->id, 'delegate_id' => $colleague->id,
            'starts_at' => now()->subDays(3), 'expires_at' => $pastEnd,
        ]);

        app(AnonymizeUserAction::class)->execute($target, $this->admin);

        foreach ([$active, $future] as $delegation) {
            $this->assertTrue($delegation->fresh()->expires_at->lessThanOrEqualTo(now()));
            $this->assertFalse(Delegation::query()->active()->whereKey($delegation->id)->exists());
        }
        $this->assertTrue($past->fresh()->expires_at->equalTo($pastEnd));

        $revocations = WorkflowAction::query()->where('action', WorkflowActionType::DelegationRevoked)->get();
        $this->assertCount(2, $revocations);
        $this->assertSame([$this->admin->id], $revocations->pluck('actor_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing([$colleague->id, $target->id], $revocations->pluck('delegated_to')->all());
    }

    public function test_import_update_mode_refuses_invalid_phone_department_and_manager(): void
    {
        $existing = User::factory()->create(['email' => 'pat@example.com', 'phone' => '+2348012345678'])->assignRole('submitter');
        $service = app(UserImportService::class);

        $analyzed = $service->analyze([
            ['name' => 'Pat', 'email' => 'pat@example.com', 'department' => '', 'manager_email' => '', 'phone' => '0801-local', 'roles' => ''],
            ['name' => 'Pat', 'email' => 'pat@example.com', 'department' => 'No Such Dept', 'manager_email' => '', 'phone' => '', 'roles' => ''],
            ['name' => 'Pat', 'email' => 'pat@example.com', 'department' => '', 'manager_email' => 'ghost@example.com', 'phone' => '', 'roles' => ''],
            ['name' => 'Pat', 'email' => 'pat@example.com', 'department' => '', 'manager_email' => 'pat@example.com', 'phone' => '', 'roles' => ''],
        ], $this->admin);

        $this->assertSame(['error', 'error', 'error', 'error'], array_column($analyzed, 'status'));
        $this->assertStringContainsString('Phone must be international format', $analyzed[0]['message']);
        $this->assertStringContainsString('No Such Dept', $analyzed[1]['message']);
        $this->assertStringContainsString('ghost@example.com', $analyzed[2]['message']);
        $this->assertStringContainsString('own manager', $analyzed[3]['message']);

        $counts = $service->commit($analyzed, $this->admin, 'update');

        $this->assertSame(0, $counts['updated']);
        $this->assertSame('+2348012345678', $existing->fresh()->phone);
    }

    public function test_import_update_mode_applies_a_valid_row_with_a_normalized_phone(): void
    {
        $department = Department::factory()->create(['name' => 'Finance']);
        $manager = User::factory()->create(['email' => 'boss@example.com'])->assignRole('approver');
        $existing = User::factory()->create(['email' => 'pat@example.com', 'phone' => null])->assignRole('submitter');
        $service = app(UserImportService::class);

        $analyzed = $service->analyze([
            ['name' => 'Pat Updated', 'email' => 'pat@example.com', 'department' => 'finance', 'manager_email' => 'boss@example.com', 'phone' => '+234 801 234 5678', 'roles' => ''],
        ], $this->admin);
        $this->assertSame('duplicate', $analyzed[0]['status']);

        $this->assertSame(1, $service->commit($analyzed, $this->admin, 'update')['updated']);

        $existing->refresh();
        $this->assertSame('Pat Updated', $existing->name);
        $this->assertSame('+2348012345678', $existing->phone);
        $this->assertSame($department->id, $existing->department_id);
        $this->assertSame($manager->id, $existing->manager_id);
    }

    public function test_import_refuses_a_phone_that_belongs_to_someone_else(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'phone' => '+2348012345678']);
        User::factory()->create(['email' => 'pat@example.com'])->assignRole('submitter');

        $analyzed = app(UserImportService::class)->analyze([
            ['name' => 'Pat', 'email' => 'pat@example.com', 'department' => '', 'manager_email' => '', 'phone' => '+2348012345678', 'roles' => ''],
            ['name' => 'New A', 'email' => 'a@example.com', 'department' => '', 'manager_email' => '', 'phone' => '+2348099999999', 'roles' => ''],
            ['name' => 'New B', 'email' => 'b@example.com', 'department' => '', 'manager_email' => '', 'phone' => '+2348099999999', 'roles' => ''],
        ], $this->admin);

        $this->assertSame(['error', 'ready', 'error'], array_column($analyzed, 'status'));
        $this->assertStringContainsString('already belongs to another person', $analyzed[0]['message']);
        $this->assertStringContainsString('already belongs to another person', $analyzed[2]['message']);
    }

    public function test_import_reports_a_row_whose_manager_row_failed(): void
    {
        $service = app(UserImportService::class);
        $analyzed = $service->analyze([
            ['name' => 'Boss', 'email' => 'boss@example.com', 'department' => 'Missing Dept', 'manager_email' => '', 'phone' => '', 'roles' => ''],
            ['name' => 'Worker', 'email' => 'worker@example.com', 'department' => '', 'manager_email' => 'boss@example.com', 'phone' => '', 'roles' => ''],
            ['name' => 'Intern', 'email' => 'intern@example.com', 'department' => '', 'manager_email' => 'worker@example.com', 'phone' => '', 'roles' => ''],
        ], $this->admin);

        $this->assertSame(['error', 'error', 'error'], array_column($analyzed, 'status'));
        $this->assertStringContainsString('boss@example.com is on line 2', $analyzed[1]['message']);
        $this->assertStringContainsString('worker@example.com is on line 3', $analyzed[2]['message']);

        $service->commit($analyzed, $this->admin);
        $this->assertFalse(User::query()->where('email', 'worker@example.com')->exists());
    }

    public function test_import_reports_an_update_whose_new_manager_row_hit_the_active_user_limit(): void
    {
        User::factory()->create(['email' => 'worker@example.com'])->assignRole('submitter');
        // Room for exactly one more active account.
        SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->update([
            'max_active_users' => User::query()->where('is_active', true)->count() + 1,
        ]);

        $analyzed = app(UserImportService::class)->analyze([
            ['name' => 'First', 'email' => 'first@example.com', 'department' => '', 'manager_email' => '', 'phone' => '', 'roles' => ''],
            ['name' => 'Boss', 'email' => 'boss@example.com', 'department' => '', 'manager_email' => '', 'phone' => '', 'roles' => ''],
            ['name' => 'Worker', 'email' => 'worker@example.com', 'department' => '', 'manager_email' => 'boss@example.com', 'phone' => '', 'roles' => ''],
        ], $this->admin);

        $this->assertSame(['ready', 'error', 'error'], array_column($analyzed, 'status'));
        $this->assertStringContainsString('boss@example.com is on line 3', $analyzed[2]['message']);
    }

    public function test_import_still_links_a_manager_created_on_an_earlier_valid_row(): void
    {
        $service = app(UserImportService::class);
        $analyzed = $service->analyze([
            ['name' => 'Boss', 'email' => 'boss@example.com', 'department' => '', 'manager_email' => '', 'phone' => '', 'roles' => ''],
            ['name' => 'Worker', 'email' => 'worker@example.com', 'department' => '', 'manager_email' => 'boss@example.com', 'phone' => '', 'roles' => ''],
        ], $this->admin);

        $this->assertSame(['ready', 'ready'], array_column($analyzed, 'status'));
        $service->commit($analyzed, $this->admin);

        $boss = User::query()->where('email', 'boss@example.com')->firstOrFail();
        $this->assertSame($boss->id, User::query()->where('email', 'worker@example.com')->firstOrFail()->manager_id);
    }
}
