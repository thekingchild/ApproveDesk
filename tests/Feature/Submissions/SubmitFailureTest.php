<?php

namespace Tests\Feature\Submissions;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\AssigneeType;
use App\Enums\RequestFieldType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Department;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-07 (refused submits), RV-BUG-12 (file fields keep their own
 * uploads), RV-BUG-21 (API information replies) and RV-BUG-33 (required
 * custom fields on every submit path).
 */
class SubmitFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $department = Department::query()->create(['name' => 'Operations']);
        $this->requester = User::factory()->create(['department_id' => $department->id])->assignRole('submitter');
    }

    public function test_refused_wizard_submit_keeps_the_work_as_a_draft(): void
    {
        $this->onlyTheRequesterApproves();

        Livewire::actingAs($this->requester)->test('pages::submissions.create')
            ->set('title', 'New laptop')
            ->set('attachments', [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')])
            ->call('submit')
            ->assertRedirect();

        $draft = Submission::query()->where('submitter_id', $this->requester->id)->sole();
        $this->assertSame(SubmissionStatus::Draft, $draft->status);
        $this->assertNull($draft->workflowInstance);
        $this->assertSame(['quote.pdf'], $draft->files()->pluck('original_name')->all());
    }

    public function test_refused_custom_submit_reuses_one_draft_across_retries(): void
    {
        $this->onlyTheRequesterApproves();
        $type = $this->customType([['key' => 'reason', 'type' => RequestFieldType::ShortText]]);

        $component = Livewire::actingAs($this->requester)
            ->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('values.reason', 'Laptop')
            ->call('review');
        $component->call('submit')->assertRedirect();
        $component->call('submit')->assertRedirect();

        $draft = Submission::query()->where('submitter_id', $this->requester->id)->sole();
        $this->assertSame(SubmissionStatus::Draft, $draft->status);
    }

    public function test_api_create_and_submit_is_all_or_nothing(): void
    {
        $this->onlyTheRequesterApproves();
        Sanctum::actingAs($this->requester, ['submissions:write', 'submissions:read']);

        $this->postJson('/api/v1/submissions', ['type' => 'memo', 'title' => 'Self', 'submit' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submit');

        $this->assertSame(0, Submission::query()->count());
    }

    public function test_api_submit_refusal_is_a_validation_error(): void
    {
        $this->onlyTheRequesterApproves();
        $draft = Submission::factory()->create(['submitter_id' => $this->requester->id, 'status' => SubmissionStatus::Draft]);
        Sanctum::actingAs($this->requester, ['submissions:write', 'submissions:read']);

        $this->postJson("/api/v1/submissions/{$draft->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submit');

        $this->assertSame(SubmissionStatus::Draft, $draft->fresh()->status);
    }

    public function test_each_file_field_keeps_its_own_uploads(): void
    {
        WorkflowTemplate::factory()->default()->sequential([User::factory()->create()])->create();
        $type = $this->customType([
            ['key' => 'quote', 'type' => RequestFieldType::File],
            ['key' => 'invoice', 'type' => RequestFieldType::File],
        ]);

        Livewire::actingAs($this->requester)->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('uploads.quote', [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')])
            ->set('uploads.invoice', [
                UploadedFile::fake()->create('invoice-1.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('invoice-2.pdf', 10, 'application/pdf'),
            ])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $submission = Submission::query()->where('submitter_id', $this->requester->id)->sole();
        $values = $submission->fieldValues()->with('field')->get()
            ->mapWithKeys(fn ($value): array => [$value->field->key => $value->resolvedValue()]);
        $names = fn (array $ids): array => $submission->files()->whereIn('id', $ids)->orderBy('original_name')->pluck('original_name')->all();

        $this->assertSame(['quote.pdf'], $names((array) $values['quote']));
        $this->assertSame(['invoice-1.pdf', 'invoice-2.pdf'], $names((array) $values['invoice']));
    }

    public function test_api_cannot_submit_a_custom_draft_missing_required_fields(): void
    {
        WorkflowTemplate::factory()->default()->sequential([User::factory()->create()])->create();
        $type = $this->customType([['key' => 'reason', 'type' => RequestFieldType::ShortText, 'is_required' => true, 'label' => 'Reason']]);
        Livewire::actingAs($this->requester)->test('pages::submissions.create-custom', ['requestType' => $type])
            ->call('saveDraft')
            ->assertHasNoErrors();
        $draft = Submission::query()->where('submitter_id', $this->requester->id)->sole();
        Sanctum::actingAs($this->requester, ['submissions:write', 'submissions:read']);

        $this->postJson("/api/v1/submissions/{$draft->id}/submit")
            ->assertUnprocessable()
            ->assertJsonPath('errors.submit.0', 'Complete the required fields before submitting: Reason.');

        $this->assertSame(SubmissionStatus::Draft, $draft->fresh()->status);
    }

    public function test_api_answer_to_an_information_request_needs_a_reply(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create(['submitter_id' => $this->requester->id, 'status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), $approver, WorkflowActionType::RequestInformation, 'Which cost centre?');
        Sanctum::actingAs($this->requester, ['submissions:write', 'submissions:read']);

        $this->postJson("/api/v1/submissions/{$submission->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $this->assertSame(SubmissionStatus::AwaitingInformation, $submission->fresh()->status);

        $this->postJson("/api/v1/submissions/{$submission->id}/submit", ['comment' => 'Cost centre 4410'])->assertOk();
        $this->assertSame(SubmissionStatus::InReview, $submission->fresh()->status);
        $this->assertSame(['Cost centre 4410'], $submission->comments()->pluck('body')->all());
    }

    public function test_refused_resubmit_does_not_post_the_reply(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $type = $this->customType([['key' => 'reason', 'type' => RequestFieldType::ShortText, 'is_required' => true, 'label' => 'Reason']]);
        Livewire::actingAs($this->requester)->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('values.reason', 'Laptop')
            ->call('review')
            ->call('submit');
        $submission = Submission::query()->where('submitter_id', $this->requester->id)->sole();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), $approver, WorkflowActionType::RequestInformation, 'Which model?');
        $submission->fieldValues()->delete();

        Livewire::actingAs($this->requester)->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->set('resubmissionComment', 'The 14-inch one')
            ->call('resubmit')
            ->assertHasErrors('resubmissionComment')
            ->assertNoRedirect();

        $this->assertSame(SubmissionStatus::AwaitingInformation, $submission->fresh()->status);
        $this->assertSame(0, $submission->comments()->count());
    }

    private function onlyTheRequesterApproves(): void
    {
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [$this->requester->id],
        ]);
    }

    /** @param list<array<string, mixed>> $fields */
    private function customType(array $fields): RequestType
    {
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        foreach ($fields as $position => $field) {
            RequestFieldDefinition::factory()->for($version)->create([...$field, 'position' => $position]);
        }
        app(PublishRequestTypeVersionAction::class)->execute($version);

        return $type;
    }
}
