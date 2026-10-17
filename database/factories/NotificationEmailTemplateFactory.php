<?php

namespace Database\Factories;

use App\Models\NotificationEmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationEmailTemplate>
 */
class NotificationEmailTemplateFactory extends Factory
{
    protected $model = NotificationEmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => fake()->unique()->slug(2),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'is_custom' => true,
            'updated_by' => null,
        ];
    }
}
