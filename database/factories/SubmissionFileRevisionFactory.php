<?php

namespace Database\Factories;

use App\Enums\SubmissionFileRevisionAction;
use App\Models\Submission;
use App\Models\SubmissionFileRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionFileRevision>
 */
class SubmissionFileRevisionFactory extends Factory
{
    protected $model = SubmissionFileRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'submission_file_id' => null,
            'actor_id' => null,
            'action' => SubmissionFileRevisionAction::Added,
            'original_name' => fake()->word().'.pdf',
            'sha256' => null,
            'size_bytes' => null,
        ];
    }
}
