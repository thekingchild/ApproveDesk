<?php

namespace Database\Factories;

use App\Models\WhatsappNotificationMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappNotificationMap>
 */
class WhatsappNotificationMapFactory extends Factory
{
    protected $model = WhatsappNotificationMap::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => fake()->unique()->slug(2),
            'template_name' => fake()->slug(2),
            'language' => 'en',
            'bindings' => [],
            'is_healthy' => true,
        ];
    }
}
