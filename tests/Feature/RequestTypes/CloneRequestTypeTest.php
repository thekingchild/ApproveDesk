<?php

namespace Tests\Feature\RequestTypes;

use App\Actions\Collaboration\FollowSubmissionAction;
use App\Actions\RequestTypes\CloneRequestTypeAction;
use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** RV-ARC-13 part 2: typed Action inputs, and the clone bug they exposed. */
class CloneRequestTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clone_keeps_every_field_flag(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $type = RequestType::factory()->create(['name' => 'Payroll change']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'salary', 'label' => 'Salary', 'type' => RequestFieldType::Currency, 'position' => 0,
            'is_required' => true, 'is_searchable' => true, 'is_filterable' => true, 'is_reportable' => true,
            'is_sensitive' => true, 'is_approver_summary' => true,
        ]);

        $copy = app(CloneRequestTypeAction::class)->execute($type, User::factory()->create()->assignRole('admin'));

        $field = $copy->versions()->firstOrFail()->fields()->sole();
        // The clone used to drop these, so a copied sensitive field was shown to everyone.
        $this->assertTrue($field->is_sensitive);
        $this->assertTrue($field->is_searchable && $field->is_filterable && $field->is_reportable && $field->is_approver_summary);
        $this->assertTrue($field->is_required);
    }

    public function test_following_again_without_preferences_keeps_the_followers_choice(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $requester = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->for($requester, 'submitter')->create();
        $follow = app(FollowSubmissionAction::class);

        $follow->execute($submission, $requester, $requester, notifyComments: false);
        $watcher = $follow->execute($submission, $requester, $requester);

        $this->assertFalse($watcher->notify_comments);
        $this->assertTrue($watcher->notify_status);
    }
}
