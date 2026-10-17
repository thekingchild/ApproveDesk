<?php

namespace Database\Factories;

use App\Models\RequestFieldDefinition;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionFieldValue>
 */
class SubmissionFieldValueFactory extends Factory
{
    protected $model = SubmissionFieldValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_field_definition_id' => RequestFieldDefinition::factory(),
            // The value must belong to a field of the submission's own request-type version.
            'submission_id' => fn (array $attributes) => Submission::factory()->create([
                'request_type_version_id' => RequestFieldDefinition::query()->whereKey($attributes['request_field_definition_id'])->value('request_type_version_id'),
            ])->id,
            'string_value' => fake()->words(3, true),
            'text_value' => null,
            'decimal_value' => null,
            'date_value' => null,
            'datetime_value' => null,
            'boolean_value' => null,
            'user_value_id' => null,
            'department_value_id' => null,
            'json_value' => null,
        ];
    }
}
