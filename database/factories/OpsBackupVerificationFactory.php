<?php

namespace Database\Factories;

use App\Models\OpsBackupVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpsBackupVerification>
 */
class OpsBackupVerificationFactory extends Factory
{
    protected $model = OpsBackupVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'verified_at' => now(),
            'source' => 'manual',
            'path' => null,
            'ok' => true,
            'table_counts' => [],
            'issues' => [],
            'verified_by' => null,
        ];
    }
}
