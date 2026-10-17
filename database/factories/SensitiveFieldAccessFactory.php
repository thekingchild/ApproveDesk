<?php

namespace Database\Factories;

use App\Models\RequestFieldDefinition;
use App\Models\SensitiveFieldAccess;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SensitiveFieldAccess>
 */
class SensitiveFieldAccessFactory extends Factory
{
    protected $model = SensitiveFieldAccess::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'submission_id' => Submission::factory(),
            'request_field_definition_id' => RequestFieldDefinition::factory(),
            'context' => 'view',
            'ip_address' => null,
        ];
    }
}
