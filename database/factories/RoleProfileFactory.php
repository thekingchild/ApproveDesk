<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\RoleProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleProfile>
 */
class RoleProfileFactory extends Factory
{
    protected $model = RoleProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'label' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'description' => null,
            'is_builtin' => false,
            'is_protected' => false,
            'is_archived' => false,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
