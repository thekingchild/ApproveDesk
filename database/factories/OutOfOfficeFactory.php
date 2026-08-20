<?php

namespace Database\Factories;

use App\Models\OutOfOffice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OutOfOffice> */
class OutOfOfficeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'starts_at' => now(),
            'ends_at' => now()->addDays(3),
            'fallback_user_id' => User::factory(),
            'message' => 'Away from the office.',
        ];
    }
}
