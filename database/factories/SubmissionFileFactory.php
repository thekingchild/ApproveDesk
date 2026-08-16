<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionFile>
 */
class SubmissionFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'original_name' => fake()->word().'.pdf',
            'stored_path' => 'submissions/'.fake()->uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'uploaded_by' => User::factory(),
            'is_redacted' => false,
        ];
    }
}
