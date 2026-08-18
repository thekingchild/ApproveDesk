<?php

namespace Database\Factories;

use App\Enums\RequestTypeVersionStatus;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestTypeVersion> */
class RequestTypeVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'request_type_id' => RequestType::factory(),
            'version' => 1,
            'status' => RequestTypeVersionStatus::Draft,
            'schema_hash' => null,
            'created_by' => null,
            'published_at' => null,
        ];
    }
}
