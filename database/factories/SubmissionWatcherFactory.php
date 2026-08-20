<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionWatcher> */
class SubmissionWatcherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'user_id' => User::factory(),
            'added_by_id' => null,
            'notify_comments' => true,
            'notify_status' => true,
        ];
    }
}
