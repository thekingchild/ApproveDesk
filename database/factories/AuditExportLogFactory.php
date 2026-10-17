<?php

namespace Database\Factories;

use App\Models\AuditExportLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditExportLog>
 */
class AuditExportLogFactory extends Factory
{
    protected $model = AuditExportLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'submission_id' => null,
            'kind' => 'evidence_pack',
        ];
    }
}
