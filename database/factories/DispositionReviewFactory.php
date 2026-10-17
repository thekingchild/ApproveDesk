<?php

namespace Database\Factories;

use App\Enums\DispositionStatus;
use App\Models\DispositionReview;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispositionReview>
 */
class DispositionReviewFactory extends Factory
{
    protected $model = DispositionReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'status' => DispositionStatus::Pending,
            'requested_by' => User::factory(),
            'reviewed_by' => null,
            'notes' => null,
            'reviewed_at' => null,
        ];
    }
}
