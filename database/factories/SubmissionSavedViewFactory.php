<?php

namespace Database\Factories;

use App\Models\RequestType;
use App\Models\SubmissionSavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionSavedView>
 */
class SubmissionSavedViewFactory extends Factory
{
    protected $model = SubmissionSavedView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'request_type_id' => RequestType::factory(),
            'name' => fake()->unique()->words(2, true),
            'filters' => null,
            'columns' => null,
        ];
    }
}
