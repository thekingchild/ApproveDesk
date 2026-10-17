<?php

namespace Database\Factories;

use App\Models\WhatsappMessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappMessageTemplate>
 */
class WhatsappMessageTemplateFactory extends Factory
{
    protected $model = WhatsappMessageTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'language' => 'en',
            'status' => fake()->word(),
            'category' => null,
            'components' => null,
            'placeholder_count' => 0,
            'fetched_at' => null,
        ];
    }
}
