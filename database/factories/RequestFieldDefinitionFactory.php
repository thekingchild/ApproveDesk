<?php

namespace Database\Factories;

use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestTypeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestFieldDefinition> */
class RequestFieldDefinitionFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->unique()->lexify('field_????????');

        return [
            'request_type_version_id' => RequestTypeVersion::factory(),
            'key' => $key,
            'label' => str($key)->replace('_', ' ')->title()->toString(),
            'type' => RequestFieldType::ShortText,
            'position' => 0,
            'is_required' => false,
            'is_searchable' => false,
            'is_filterable' => false,
            'is_reportable' => false,
            'is_sensitive' => false,
            'is_approver_summary' => false,
            'configuration' => null,
        ];
    }
}
