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

    public function test_defaults_limits_and_nested_choice_rules_are_compiled(): void
    {
        $version = RequestTypeVersion::factory()->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'summary',
            'label' => 'Summary',
            'type' => RequestFieldType::ShortText,
            'configuration' => ['default' => 'Draft summary', 'max_length' => 5],
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'amount',
            'type' => RequestFieldType::Number,
            'position' => 1,
            'configuration' => ['min' => 10, 'max' => 20],
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'choices',
            'type' => RequestFieldType::MultiSelect,
            'position' => 2,
            'configuration' => ['options' => ['One', 'Two']],
        ]);

        $compiler = app(RequestSchemaCompiler::class);

        $this->assertSame('Draft summary', $compiler->defaults($version)['summary']);

        foreach ([
            ['summary' => 'too long'],
            ['summary' => 'valid', 'amount' => 9],
            ['summary' => 'valid', 'amount' => 21],
            ['summary' => 'valid', 'amount' => 15, 'choices' => ['Three']],
        ] as $values) {
            try {
                $compiler->validate($version, $values);
                $this->fail('Expected configured validation rules to reject the value.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_condition_operator_controls_visibility_and_required_state(): void
    {
        $version = RequestTypeVersion::factory()->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'trigger',
            'type' => RequestFieldType::ShortText,
        ]);
        $conditions = [
            'equals' => ['value' => 'yes', 'matching' => 'yes', 'not_matching' => 'no'],
            'not_equals' => ['value' => 'yes', 'matching' => 'no', 'not_matching' => 'yes'],
            'in' => ['value' => ['yes', 'maybe'], 'matching' => 'maybe', 'not_matching' => 'no'],
            'not_empty' => ['value' => null, 'matching' => 'present', 'not_matching' => ''],
        ];

        foreach ($conditions as $position => $condition) {
            RequestFieldDefinition::factory()->for($version)->create([
                'key' => 'conditional_'.$position,
                'type' => RequestFieldType::ShortText,
                'position' => array_search($position, array_keys($conditions), true) + 1,
                'configuration' => [
                    'visibility' => ['field' => 'trigger', 'operator' => $position, 'value' => $condition['value']],
                    'required_when' => ['field' => 'trigger', 'operator' => $position, 'value' => $condition['value']],
                ],
            ]);
        }

        $compiler = app(RequestSchemaCompiler::class);
        $compiler->assertSchemaValid($version->fields);

        foreach ($conditions as $operator => $condition) {
            $field = $version->fields->firstWhere('key', 'conditional_'.$operator);
            $this->assertTrue($compiler->isVisible($field, ['trigger' => $condition['matching']]));
            $this->assertTrue($compiler->isRequired($field, ['trigger' => $condition['matching']]));
            $this->assertFalse($compiler->isVisible($field, ['trigger' => $condition['not_matching']]));
        }
    }

    public function test_schema_rejects_empty_choices_future_references_and_unknown_operators(): void
    {
        foreach (['empty_choice', 'future_reference', 'unknown_operator'] as $scenario) {
            $version = RequestTypeVersion::factory()->create();

            if ($scenario === 'empty_choice') {
                RequestFieldDefinition::factory()->for($version)->create([
                    'type' => RequestFieldType::Select,
                    'configuration' => ['options' => []],
                ]);
            } else {
                RequestFieldDefinition::factory()->for($version)->create([
                    'key' => 'conditional',
                    'configuration' => ['visibility' => [
                        'field' => $scenario === 'future_reference' ? 'later' : 'conditional',
                        'operator' => $scenario === 'unknown_operator' ? 'contains' : 'equals',
                        'value' => 'yes',
                    ]],
                ]);
                RequestFieldDefinition::factory()->for($version)->create([
                    'key' => 'later',
                    'position' => 1,
                ]);
            }

            try {
                app(RequestSchemaCompiler::class)->assertSchemaValid($version->fields);
                $this->fail("Expected invalid schema scenario [{$scenario}] to fail.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
