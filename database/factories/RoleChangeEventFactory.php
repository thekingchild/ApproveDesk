<?php

namespace Database\Factories;

use App\Enums\RoleChangeAction;
use App\Models\RoleChangeEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleChangeEvent>
 */
class RoleChangeEventFactory extends Factory
{
    protected $model = RoleChangeEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'role_id' => null,
            'target_user_id' => null,
            'action' => RoleChangeAction::RoleCreated,
            'before' => null,
            'after' => null,
        ];
    }
}
