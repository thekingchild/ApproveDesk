<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionRevision>
 */
class SubmissionRevisionFactory extends Factory
{
    protected $model = SubmissionRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'actor_id' => null,
            'summary' => fake()->sentence(3),
            'before' => null,
            'after' => null,
        ];
    }
}
