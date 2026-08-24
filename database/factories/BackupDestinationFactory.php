<?php

namespace Database\Factories;

use App\Enums\BackupDestinationKind;
use App\Models\BackupDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupDestination>
 */
class BackupDestinationFactory extends Factory
{
    protected $model = BackupDestination::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Local disk',
            'kind' => BackupDestinationKind::Local,
            'config' => null,
            'enabled' => true,
        ];
    }

    public function s3(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Object storage',
            'kind' => BackupDestinationKind::S3,
        ]);
    }

    public function drive(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Google Drive',
            'kind' => BackupDestinationKind::GoogleDrive,
        ]);
    }
}
