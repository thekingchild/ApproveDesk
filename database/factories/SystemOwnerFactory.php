<?php

namespace Database\Factories;

use App\Models\SystemOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemOwner>
 */
class SystemOwnerFactory extends Factory
{
    protected $model = SystemOwner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => SystemOwner::SINGLETON_ID,
            'user_id' => User::factory(),
        ];
    }
}
