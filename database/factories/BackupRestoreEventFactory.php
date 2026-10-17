<?php

namespace Database\Factories;

use App\Enums\BackupSnapshotKind;
use App\Models\BackupRestoreEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRestoreEvent>
 */
class BackupRestoreEventFactory extends Factory
{
    protected $model = BackupRestoreEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'snapshot_id' => null,
            'actor_id' => null,
            'actor_name' => fake()->name(),
            'kind' => BackupSnapshotKind::Database,
            'snapshot_name' => 'database-'.fake()->date('Ymd').'.sql.gz',
            'discarded_workflow_actions' => null,
            'safety_snapshot_id' => null,
        ];
    }
}
