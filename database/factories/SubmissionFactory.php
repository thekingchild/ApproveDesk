<?php

namespace Database\Factories;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Models\Department;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(SubmissionType::cases()),
            'request_type_version_id' => fn () => RequestTypeVersion::general()->id,
            'title' => fake()->sentence(6),
            'body' => fake()->paragraphs(2, true),
            'submitter_id' => User::factory(),
            'department_id' => null,
            'priority' => SubmissionPriority::Normal,
            'due_date' => null,
            'status' => SubmissionStatus::Pending,
            'metadata' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => SubmissionStatus::Draft]);
    }

    public function priority(SubmissionPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    /**
     * @param  Department|int  $department
     */
    public function byDepartment($department): static
    {
        return $this->state(fn () => ['department_id' => $department]);
    }
}
