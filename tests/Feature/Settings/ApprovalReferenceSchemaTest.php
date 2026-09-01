<?php

namespace Tests\Feature\Settings;

use App\Actions\Settings\UpdateApprovalReferenceSchemaAction;
use App\Models\ApprovalReferenceCounter;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\ReferenceNumberGenerator;
use App\Support\ApprovalReferenceSchema;
use App\Support\RandomDigitGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalReferenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (['prefix', 'separator', 'year_pattern', 'month_pattern', 'digit_length', 'generation_mode'] as $key) {
            Cache::forget('settings.approvals.reference.'.$key);
        }
        Carbon::setTestNow('2026-09-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_existing_installations_keep_the_current_reference_format_by_default(): void
    {
        $schema = app(ApprovalReferenceSchema::class);

        $this->assertSame('SUB-2026-00001', $schema->preview());
        $this->assertSame('SUB-2026-00001', Submission::factory()->create()->reference_number);
        $this->assertSame('SUB-2026-00002', Submission::factory()->create()->reference_number);
    }

    public function test_system_owner_can_save_a_schema_with_year_month_separator_and_six_digits(): void
    {
        $owner = $this->owner();

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'apr',
            'separator' => '/',
            'year_pattern' => 'yy',
            'month_pattern' => 'mm',
            'digit_length' => 6,
            'generation_mode' => 'sequential',
        ]);

        $this->assertSame('APR', Setting::get('approvals.reference.prefix'));
        $this->assertSame('APR/26/09/000001', app(ApprovalReferenceSchema::class)->preview());
        $this->assertSame('APR/26/09/000001', Submission::factory()->create()->reference_number);
        $this->assertSame('APR/26/09/000002', Submission::factory()->create()->reference_number);
    }

    public function test_schema_omits_disabled_date_parts_without_duplicate_separators(): void
    {
        $owner = $this->owner();

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'REQ9',
            'separator' => '-',
            'year_pattern' => 'none',
            'month_pattern' => 'none',
            'digit_length' => 4,
            'generation_mode' => 'sequential',
        ]);

        $this->assertSame('REQ9-0001', Submission::factory()->create()->reference_number);
    }

    public function test_three_letter_month_and_empty_separator_are_supported(): void
    {
        $owner = $this->owner();

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'APP',
            'separator' => '',
            'year_pattern' => 'yyyy',
            'month_pattern' => 'mmm',
            'digit_length' => 5,
            'generation_mode' => 'sequential',
        ]);

        $this->assertSame('APP2026SEP00001', Submission::factory()->create()->reference_number);
    }

    public function test_sequential_counters_reset_by_active_period_and_resume_when_returning(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'APR',
            'separator' => '-',
            'year_pattern' => 'yyyy',
            'month_pattern' => 'mm',
            'digit_length' => 4,
            'generation_mode' => 'sequential',
        ]);

        $this->assertSame('APR-2026-09-0001', Submission::factory()->create()->reference_number);
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->assertSame('APR-2026-10-0001', Submission::factory()->create()->reference_number);
        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->assertSame('APR-2026-09-0002', Submission::factory()->create()->reference_number);
        $this->assertSame(2, ApprovalReferenceCounter::query()->count());
        $this->assertSame(2, ApprovalReferenceCounter::query()->where('scope', '4:APR-2026-09-')->value('current_value'));
    }

    public function test_digit_length_changes_use_independent_counters_and_resume_the_earlier_length(): void
    {
        $owner = $this->owner();
        $base = [
            'prefix' => 'APP',
            'separator' => '-',
            'year_pattern' => 'none',
            'month_pattern' => 'none',
            'generation_mode' => 'sequential',
        ];

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [...$base, 'digit_length' => 4]);
        $this->assertSame('APP-0001', Submission::factory()->create()->reference_number);

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [...$base, 'digit_length' => 5]);
        $this->assertSame('APP-00001', Submission::factory()->create()->reference_number);

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [...$base, 'digit_length' => 4]);
        $this->assertSame('APP-0002', Submission::factory()->create()->reference_number);
        $this->assertDatabaseCount('approval_reference_counters', 2);
    }

    public function test_sequential_generation_fails_when_the_digit_range_is_exhausted(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'MAX',
            'separator' => '-',
            'year_pattern' => 'none',
            'month_pattern' => 'none',
            'digit_length' => 4,
            'generation_mode' => 'sequential',
        ]);

        ApprovalReferenceCounter::query()->create([
            'scope' => '4:MAX-',
            'current_value' => 9999,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The sequential approval ID range for this format is exhausted.');
        Submission::factory()->create();
    }

    public function test_random_mode_uses_exactly_the_configured_number_of_digits(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'RND',
            'separator' => '-',
            'year_pattern' => 'yy',
            'month_pattern' => 'none',
            'digit_length' => 8,
            'generation_mode' => 'random',
        ]);

        $first = Submission::factory()->create()->reference_number;
        $second = Submission::factory()->create()->reference_number;

        $this->assertMatchesRegularExpression('/^RND-26-\d{8}$/', $first);
        $this->assertMatchesRegularExpression('/^RND-26-\d{8}$/', $second);
        $this->assertNotSame($first, $second);
    }

    public function test_random_mode_retries_collisions_before_returning_a_unique_id(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'RND',
            'separator' => '-',
            'year_pattern' => 'yy',
            'month_pattern' => 'none',
            'digit_length' => 4,
            'generation_mode' => 'random',
        ]);
        Submission::factory()->create(['reference_number' => 'RND-26-1234']);

        $numbers = new class extends RandomDigitGenerator
        {
            public int $calls = 0;

            public function next(int $length): string
            {
                $this->calls++;

                return $this->calls === 1 ? '1234' : '5678';
            }
        };
        $this->app->instance(RandomDigitGenerator::class, $numbers);

        $this->assertSame('RND-26-5678', Submission::factory()->create()->reference_number);
        $this->assertSame(2, $numbers->calls);
    }

    public function test_random_mode_does_not_reuse_a_soft_deleted_approval_id(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'RND',
            'separator' => '-',
            'year_pattern' => 'yy',
            'month_pattern' => 'none',
            'digit_length' => 4,
            'generation_mode' => 'random',
        ]);
        Submission::factory()->create(['reference_number' => 'RND-26-1234'])->delete();

        $numbers = new class extends RandomDigitGenerator
        {
            public int $calls = 0;

            public function next(int $length): string
            {
                $this->calls++;

                return $this->calls === 1 ? '1234' : '5678';
            }
        };
        $this->app->instance(RandomDigitGenerator::class, $numbers);

        $this->assertSame('RND-26-5678', Submission::factory()->create()->reference_number);
        $this->assertSame(2, $numbers->calls);
    }

    public function test_random_mode_reserves_a_candidate_so_concurrent_draws_cannot_reuse_it(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'RND',
            'separator' => '-',
            'year_pattern' => 'yy',
            'month_pattern' => 'none',
            'digit_length' => 4,
            'generation_mode' => 'random',
        ]);

        $numbers = new class extends RandomDigitGenerator
        {
            public int $calls = 0;

            public function next(int $length): string
            {
                $this->calls++;

                return $this->calls <= 2 ? '1234' : '5678';
            }
        };
        $this->app->instance(RandomDigitGenerator::class, $numbers);

        $first = app(ReferenceNumberGenerator::class)->next();
        $second = app(ReferenceNumberGenerator::class)->next();

        $this->assertSame('RND-26-1234', $first);
        $this->assertSame('RND-26-5678', $second);
        $this->assertGreaterThanOrEqual(3, $numbers->calls);
    }

    public function test_only_the_singleton_system_owner_can_view_and_save_the_schema_card(): void
    {
        $owner = $this->owner();
        $delegatedManager = User::factory()->create();
        $delegatedManager->givePermissionTo('approval_settings.manage');
        $roleOnlySuperAdmin = User::factory()->create()->assignRole('super-admin');

        Livewire::actingAs($owner)
            ->test('pages::settings.approvals')
            ->assertSee('Approval ID format')
            ->assertSet('referencePrefix', 'SUB')
            ->set('referencePrefix', 'APR')
            ->set('referenceSeparator', '/')
            ->set('referenceYearPattern', 'yy')
            ->set('referenceMonthPattern', 'mm')
            ->set('referenceDigitLength', 6)
            ->set('referenceGenerationMode', 'sequential')
            ->assertSee('APR/26/09/000001')
            ->call('saveReferenceSchema')
            ->assertHasNoErrors()
            ->assertSee('APR/26/09/000001');

        Livewire::actingAs($delegatedManager)
            ->test('pages::settings.approvals')
            ->assertDontSee('Approval ID format')
            ->assertNotSet('referencePrefix', 'APR')
            ->assertNotSet('referenceGenerationMode', 'random');

        Livewire::actingAs($roleOnlySuperAdmin)
            ->test('pages::settings.approvals')
            ->assertDontSee('Approval ID format')
            ->assertNotSet('referencePrefix', 'APR');

        $this->expectException(AuthorizationException::class);
        app(UpdateApprovalReferenceSchemaAction::class)->execute($delegatedManager, [
            'prefix' => 'BAD',
            'separator' => '-',
            'year_pattern' => 'yyyy',
            'month_pattern' => 'none',
            'digit_length' => 5,
            'generation_mode' => 'sequential',
        ]);
    }

    public function test_no_separator_schemas_with_zero_ending_prefixes_keep_independent_counters(): void
    {
        $owner = $this->owner();
        $base = [
            'separator' => '',
            'year_pattern' => 'none',
            'month_pattern' => 'none',
            'digit_length' => 4,
            'generation_mode' => 'sequential',
        ];

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, ['prefix' => 'A0', ...$base]);
        $this->assertSame('A00001', Submission::factory()->create()->reference_number);

        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, ['prefix' => 'A', ...$base]);
        $this->assertSame('A0001', Submission::factory()->create()->reference_number);
        $this->assertDatabaseCount('approval_reference_counters', 2);
    }

    public function test_invalid_schema_values_are_rejected_without_changing_saved_settings(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test('pages::settings.approvals')
            ->set('referencePrefix', 'bad prefix!')
            ->set('referenceDigitLength', 3)
            ->call('saveReferenceSchema')
            ->assertHasErrors(['referencePrefix', 'referenceDigitLength']);

        $this->assertDatabaseMissing('settings', ['key' => 'approvals.reference.prefix']);
    }

    public function test_delegated_manager_saving_ordinary_approval_settings_preserves_owner_id_schema(): void
    {
        $owner = $this->owner();
        app(UpdateApprovalReferenceSchemaAction::class)->execute($owner, [
            'prefix' => 'KEEP',
            'separator' => '-',
            'year_pattern' => 'yyyy',
            'month_pattern' => 'none',
            'digit_length' => 5,
            'generation_mode' => 'sequential',
        ]);
        $manager = User::factory()->create();
        $manager->givePermissionTo('approval_settings.manage');

        Livewire::actingAs($manager)
            ->test('pages::settings.approvals')
            ->set('emailLinkExpirationHours', 96)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('KEEP', Setting::get('approvals.reference.prefix'));
        $this->assertSame('96', Setting::get('approvals.email_link_expiration_hours'));
    }

    private function owner(): User
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create([
            'id' => SystemOwner::SINGLETON_ID,
            'user_id' => $owner->id,
        ]);

        return $owner;
    }
}
