<?php

namespace Database\Factories;

use App\Models\OpsSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpsSnapshot>
 */
class OpsSnapshotFactory extends Factory
{
    protected $model = OpsSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recorded_at' => now(),
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'dead_letter_webhooks' => 0,
            'dead_letter_integrations' => 0,
            'failed_notifications' => 0,
            'stuck_workflows' => 0,
            'oldest_pending_seconds' => null,
        ];
    }
}
