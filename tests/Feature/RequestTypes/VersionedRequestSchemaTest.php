<?php

namespace Tests\Feature\RequestTypes;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Actions\Submissions\CreateSubmissionAction;
use App\Actions\Submissions\SyncLegacySubmissionFieldValuesAction;
use App\DTOs\SubmissionData;
use App\Enums\RequestFieldType;
use App\Enums\RequestTypeVersionStatus;
use App\Enums\SubmissionType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VersionedRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_request_schema_is_installed_and_new_submissions_reference_it(): void
    {
        $general = RequestType::query()->where('slug', RequestType::GENERAL_SLUG)->firstOrFail();
        $version = $general->publishedVersion()->with('fields')->firstOrFail();

        $this->assertTrue($general->is_system);
        $this->assertSame(RequestTypeVersionStatus::Published, $version->status);
        $this->assertSame(
            ['type', 'title', 'body', 'priority', 'department', 'due_date'],
            $version->fields->pluck('key')->all(),
        );
        $this->assertTrue(Schema::hasColumns('submission_field_values', [
            'string_value',
            'text_value',
            'decimal_value',
            'date_value',
            'datetime_value',
            'boolean_value',
            'user_value_id',
            'department_value_id',
            'json_value',
        ]));

        $submitter = User::factory()->create();
        $submission = app(CreateSubmissionAction::class)->execute(new SubmissionData(
            type: SubmissionType::Memo,
            title: 'General request values',
            body: '<p>Typed body</p>',
        ), $submitter, true);

        $this->assertSame($version->id, $submission->request_type_version_id);
        $this->assertSame(4, $submission->fieldValues()->count());
        $this->assertDatabaseHas('submission_field_values', [
            'submission_id' => $submission->id,
            'text_value' => '<p>Typed body</p>',
        ]);

        $submission->update(['body' => null]);
        app(SyncLegacySubmissionFieldValuesAction::class)->execute($submission);

        $this->assertSame(3, $submission->fieldValues()->count());
    }

    public function test_publish_action_records_a_canonical_hash_and_published_versions_are_immutable(): void
    {
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        $field = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'amount',
            'type' => RequestFieldType::Currency,
            'is_required' => true,
        ]);

        $published = app(PublishRequestTypeVersionAction::class)->execute($version);

        $this->assertSame(RequestTypeVersionStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $published->schema_hash);

        $this->assertDomainException(fn () => $published->update(['schema_hash' => str_repeat('a', 64)]), 'version update');
        $this->assertDomainException(fn () => $field->update(['label' => 'Changed amount']), 'field update');
        $field->refresh();
        $draftVersion = RequestTypeVersion::factory()->for($type)->create(['version' => 2]);
        $this->assertDomainException(
            fn () => $field->update(['request_type_version_id' => $draftVersion->id]),
            'field reassignment',
        );
        $field->refresh();
        $this->assertDomainException(fn () => $field->delete(), 'field deletion');
        $this->assertDomainException(fn () => RequestFieldDefinition::factory()->for($published)->create(), 'field creation');
        $published->refresh();
        $this->assertDomainException(fn () => $published->delete(), 'version deletion');
    }

    public function test_an_empty_or_already_published_version_cannot_be_published(): void
    {
        $emptyVersion = RequestTypeVersion::factory()->create();

        $this->assertDomainException(fn () => app(PublishRequestTypeVersionAction::class)->execute($emptyVersion));

        $version = RequestTypeVersion::factory()->create();
        RequestFieldDefinition::factory()->for($version)->create();
        $published = app(PublishRequestTypeVersionAction::class)->execute($version);

        $this->assertDomainException(fn () => app(PublishRequestTypeVersionAction::class)->execute($published));
    }

    public function test_submission_creation_rejects_draft_or_inactive_request_types(): void
    {
        $submitter = User::factory()->create();
        $draft = RequestTypeVersion::factory()->create();
        $data = new SubmissionData(
            type: SubmissionType::Document,
            title: 'Draft schema request',
            body: null,
            requestTypeVersionId: $draft->id,
        );

        $this->assertDomainException(
            fn () => app(CreateSubmissionAction::class)->execute($data, $submitter, true),
        );

        $type = RequestType::factory()->create(['is_active' => false]);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create();
        $published = app(PublishRequestTypeVersionAction::class)->execute($version);
        $inactiveData = new SubmissionData(
            type: SubmissionType::Document,
            title: 'Inactive schema request',
            body: null,
            requestTypeVersionId: $published->id,
        );

        $this->assertDomainException(
            fn () => app(CreateSubmissionAction::class)->execute($inactiveData, $submitter, true),
        );
    }

    public function test_typed_values_require_the_correct_column_and_submission_version(): void
    {
        $version = RequestTypeVersion::factory()->create();
        $amount = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'amount',
            'type' => RequestFieldType::Currency,
        ]);
        $published = app(PublishRequestTypeVersionAction::class)->execute($version);
        $submission = Submission::factory()->create(['request_type_version_id' => $published->id]);

        $value = SubmissionFieldValue::create([
            'submission_id' => $submission->id,
            'request_field_definition_id' => $amount->id,
            'decimal_value' => '2500.750000',
        ]);

        $this->assertSame('2500.750000', $value->decimal_value);
        $this->assertDomainException(fn () => SubmissionFieldValue::create([
            'submission_id' => $submission->id,
            'request_field_definition_id' => $amount->id,
            'string_value' => '2500.75',
        ]));

        $otherVersion = RequestTypeVersion::factory()->create();
        $otherField = RequestFieldDefinition::factory()->for($otherVersion)->create();
        $this->assertDomainException(fn () => SubmissionFieldValue::create([
            'submission_id' => $submission->id,
            'request_field_definition_id' => $otherField->id,
            'string_value' => 'wrong version',
        ]));
    }

    public function test_a_new_draft_version_does_not_change_an_existing_submission_schema(): void
    {
        $type = RequestType::factory()->create();
        $versionOne = RequestTypeVersion::factory()->for($type)->create(['version' => 1]);
        RequestFieldDefinition::factory()->for($versionOne)->create(['key' => 'reason', 'label' => 'Original reason']);
        $publishedVersionOne = app(PublishRequestTypeVersionAction::class)->execute($versionOne);
        $submission = Submission::factory()->create(['request_type_version_id' => $publishedVersionOne->id]);

        $versionTwo = RequestTypeVersion::factory()->for($type)->create(['version' => 2]);
        RequestFieldDefinition::factory()->for($versionTwo)->create(['key' => 'reason', 'label' => 'Revised reason']);

        $submission->load('requestTypeVersion.fields');

        $this->assertSame(1, $submission->requestTypeVersion->version);
        $this->assertSame('Original reason', $submission->requestTypeVersion->fields->first()->label);
    }

    public function test_an_old_submission_renders_its_original_published_schema_after_a_new_version_is_published(): void
    {
        $owner = User::factory()->create();
        $type = RequestType::factory()->create(['name' => 'Leave Request']);
        $versionOne = RequestTypeVersion::factory()->for($type)->create(['version' => 1]);
        RequestFieldDefinition::factory()->for($versionOne)->create([
            'key' => 'reason',
            'label' => 'Original leave reason',
            'type' => RequestFieldType::LongText,
            'is_required' => true,
        ]);
        $publishedVersionOne = app(PublishRequestTypeVersionAction::class)->execute($versionOne);
        $submission = Submission::factory()->for($owner, 'submitter')->create([
            'request_type_version_id' => $publishedVersionOne->id,
            'title' => 'Historical leave request',
        ]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, ['reason' => 'Original stored answer']);

        $versionTwo = RequestTypeVersion::factory()->for($type)->create(['version' => 2]);
        RequestFieldDefinition::factory()->for($versionTwo)->create([
            'key' => 'reason',
            'label' => 'Replacement reason label',
            'type' => RequestFieldType::LongText,
        ]);
        RequestFieldDefinition::factory()->for($versionTwo)->create([
            'key' => 'manager_note',
            'label' => 'New manager note',
            'position' => 1,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($versionTwo);

        $this->actingAs($owner)
            ->get(route('submissions.show', $submission))
            ->assertOk()
            ->assertSee('Version 1')
            ->assertSee('Original leave reason')
            ->assertSee('Original stored answer')
            ->assertDontSee('Replacement reason label')
            ->assertDontSee('New manager note');
    }

    private function assertDomainException(callable $callback, string $operation = 'operation'): void
    {
        try {
            $callback();
            $this->fail("Expected a DomainException to be thrown for {$operation}.");
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
