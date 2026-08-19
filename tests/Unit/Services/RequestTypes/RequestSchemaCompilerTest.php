<?php

namespace Tests\Unit\Services\RequestTypes;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Enums\RequestFieldType;
use App\Models\Department;
use App\Models\RequestFieldDefinition;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\RequestTypes\RequestSchemaCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestSchemaCompilerTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{RequestFieldType, mixed, string, array<string, mixed>}> */
    public static function typedFields(): iterable
    {
        yield 'short text' => [RequestFieldType::ShortText, 'hello', 'string_value', []];
        yield 'long text' => [RequestFieldType::LongText, 'long copy', 'text_value', []];
        yield 'rich text' => [RequestFieldType::RichText, '<p>safe</p><script>x</script>', 'text_value', []];
        yield 'number' => [RequestFieldType::Number, 12.5, 'decimal_value', []];
        yield 'currency' => [RequestFieldType::Currency, 99.95, 'decimal_value', []];
        yield 'date' => [RequestFieldType::Date, '2026-08-20', 'date_value', []];
        yield 'date time' => [RequestFieldType::DateTime, '2026-08-20 10:30:00', 'datetime_value', []];
        yield 'checkbox' => [RequestFieldType::Checkbox, true, 'boolean_value', []];
        yield 'select' => [RequestFieldType::Select, 'One', 'string_value', ['options' => ['One', 'Two']]];
        yield 'multi select' => [RequestFieldType::MultiSelect, ['One', 'Two'], 'json_value', ['options' => ['One', 'Two']]];
        yield 'radio' => [RequestFieldType::Radio, 'Two', 'string_value', ['options' => ['One', 'Two']]];
        yield 'url' => [RequestFieldType::Url, 'https://example.com', 'string_value', []];
        yield 'email' => [RequestFieldType::Email, 'person@example.com', 'string_value', []];
        yield 'file' => [RequestFieldType::File, [12, 13], 'json_value', ['allowed_types' => ['pdf']]];
        yield 'json' => [RequestFieldType::Json, ['value'], 'json_value', []];
    }

    #[DataProvider('typedFields')]
    public function test_each_portable_field_type_validates_and_uses_its_typed_column(RequestFieldType $type, mixed $value, string $column, array $configuration): void
    {
        $version = RequestTypeVersion::factory()->create();
        $field = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'test_field', 'type' => $type, 'is_required' => true, 'configuration' => $configuration,
        ]);
        $published = app(PublishRequestTypeVersionAction::class)->execute($version);
        $submission = Submission::factory()->create(['request_type_version_id' => $published->id]);

        app(StoreSubmissionFieldValuesAction::class)->execute($submission, ['test_field' => $value]);

        $stored = $submission->fieldValues()->sole();
        $this->assertNotNull($stored->getAttribute($column));
        if ($type === RequestFieldType::RichText) {
            $this->assertStringNotContainsString('<script>', $stored->text_value);
        }
    }

    public function test_user_department_calculation_and_constrained_conditions_are_compiled(): void
    {
        $user = User::factory()->create();
        $department = Department::factory()->create();
        $version = RequestTypeVersion::factory()->create();
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'trigger', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['yes', 'no']]]);
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'amount', 'type' => RequestFieldType::Number, 'position' => 1]);
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'owner', 'type' => RequestFieldType::User, 'position' => 2]);
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'department', 'type' => RequestFieldType::Department, 'position' => 3]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'conditional', 'type' => RequestFieldType::Email, 'position' => 4,
            'configuration' => ['visibility' => ['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes'], 'required_when' => ['field' => 'trigger', 'operator' => 'equals', 'value' => 'yes']],
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'total', 'type' => RequestFieldType::Calculated, 'position' => 5,
            'configuration' => ['calculation' => ['operation' => 'sum', 'fields' => ['amount']]],
        ]);
        $published = app(PublishRequestTypeVersionAction::class)->execute($version);
        $submission = Submission::factory()->create(['request_type_version_id' => $published->id]);

        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'trigger' => 'yes', 'amount' => 5, 'owner' => $user->id, 'department' => $department->id, 'conditional' => 'valid@example.com',
        ]);

        $this->assertSame('5.000000', $submission->fieldValues()->whereHas('field', fn ($query) => $query->where('key', 'total'))->sole()->decimal_value);
        $this->assertSame(6, $submission->fieldValues()->count());
    }

    public function test_draft_validation_allows_required_fields_to_be_incomplete(): void
    {
        $version = RequestTypeVersion::factory()->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'summary',
            'type' => RequestFieldType::ShortText,
            'is_required' => true,
        ]);

        $this->assertSame([], app(RequestSchemaCompiler::class)->validate($version, [], false));

        $this->expectException(ValidationException::class);
        app(RequestSchemaCompiler::class)->validate($version, []);
    }

    public function test_calculated_fields_reject_non_numeric_sources(): void
    {
        $version = RequestTypeVersion::factory()->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'summary',
            'type' => RequestFieldType::ShortText,
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'total',
            'type' => RequestFieldType::Calculated,
            'position' => 1,
            'configuration' => ['calculation' => ['operation' => 'sum', 'fields' => ['summary']]],
        ]);

        $this->expectException(ValidationException::class);
        app(RequestSchemaCompiler::class)->assertSchemaValid($version->fields);
    }
}
