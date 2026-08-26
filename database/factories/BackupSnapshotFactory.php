<?php

namespace Database\Factories;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupSnapshot>
 */
class BackupSnapshotFactory extends Factory
{
    protected $model = BackupSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => BackupSnapshotKind::Database,
            'destination_id' => BackupDestination::factory(),
            'status' => BackupSnapshotStatus::Success,
            'path' => 'backups/database/example.sql.gz',
            'bytes' => 128,
            'checksum' => hash('sha256', 'example'),
            'retained_until' => now()->addDays(14),
            'taken_at' => now(),
        ];
    }
}
