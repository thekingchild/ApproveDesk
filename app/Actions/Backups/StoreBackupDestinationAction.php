<?php

namespace App\Actions\Backups;

use App\Enums\BackupDestinationKind;
use App\Models\BackupDestination;
use App\Services\Backups\BackupTransportFactory;
use InvalidArgumentException;
use Throwable;

final class StoreBackupDestinationAction
{
    public function __construct(private readonly BackupTransportFactory $transports) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): BackupDestination
    {
        $kind = BackupDestinationKind::from((string) $input['kind']);
        if ($kind === BackupDestinationKind::Local) {
            throw new InvalidArgumentException('The local disk is created automatically.');
        }

        $destination = BackupDestination::query()->create([
            'name' => (string) $input['name'],
            'kind' => $kind,
            'enabled' => ! empty($input['enabled']),
        ]);

        $destination->putCredentials($this->posted($input));

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(BackupDestination $destination, array $input): BackupDestination
    {
        if ($destination->kind === BackupDestinationKind::Local) {
            $destination->forceFill(['enabled' => ! empty($input['enabled'])])->save();

            return $destination;
        }

        if (! empty($input['clear'])) {
            $destination->clearCredentials();
            $destination->forceFill(['enabled' => false])->save();

            return $destination;
        }

        $destination->forceFill([
            'name' => (string) ($input['name'] ?? $destination->name),
            'enabled' => array_key_exists('enabled', $input) ? ! empty($input['enabled']) : $destination->enabled,
        ])->save();
        $destination->putCredentials($this->posted($input));

        return $destination;
    }

    public function test(BackupDestination $destination): bool
    {
        try {
            $ok = $this->transports->make($destination)->test();
            $destination->forceFill([
                'last_tested_at' => now(),
                'last_error' => $ok ? null : 'Test connection failed.',
            ])->save();

            return $ok;
        } catch (Throwable $exception) {
            $destination->rememberError($exception->getMessage());
            $destination->forceFill(['last_tested_at' => now()])->save();

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function posted(array $input): array
    {
        $fields = ['key', 'secret', 'region', 'bucket', 'endpoint', 'path_style', 'prefix', 'folder_id', 'refresh_token', 'client_id', 'client_secret', 'service_account'];
        $posted = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $posted[$field] = is_string($input[$field]) ? $input[$field] : null;
            }
        }

        return $posted;
    }
}
