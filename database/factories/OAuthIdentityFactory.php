<?php

namespace Database\Factories;

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OAuthIdentity>
 */
class OAuthIdentityFactory extends Factory
{
    protected $model = OAuthIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => fake()->unique()->numerify('##########'),
            'email' => fake()->safeEmail(),
            'avatar' => null,
        ];
    }
}
