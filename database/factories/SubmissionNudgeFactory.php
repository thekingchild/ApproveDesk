<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionNudge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionNudge> */
class SubmissionNudgeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'actor_id' => User::factory(),
            'recipient_id' => User::factory(),
            'step_id' => null,
            'message' => fake()->sentence(),
            'scheduled_for' => now(),
            'delayed_for_quiet_hours' => false,
        ];
    }
}
