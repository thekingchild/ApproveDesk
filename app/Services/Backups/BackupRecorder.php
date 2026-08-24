<?php

namespace App\Services\Backups;

use App\Enums\BackupDestinationKind;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupSnapshot;
use App\Support\BackupSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class BackupRecorder
{
    public function __construct(private readonly BackupTransportFactory $transports) {}

    public function localDestination(): BackupDestination
    {
        $existing = BackupDestination::query()
            ->where('kind', BackupDestinationKind::Local)
            ->first();

        if ($existing instanceof BackupDestination) {
            return $existing;
        }

        return BackupDestination::query()->create([
            'name' => 'Local disk',
            'kind' => BackupDestinationKind::Local,
            'enabled' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function store(
        BackupSnapshotKind $kind,
        string $absolutePath,
        array $manifest,
        int $retainDays,
    ): BackupSnapshot {
        $name = sprintf('%s/%s-%s.%s', $kind->value, now()->format('YmdHis'), substr(hash('sha256', $absolutePath), 0, 8), pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'bin');
        Storage::disk('local')->makeDirectory('backups/'.$kind->value);
        $relative = 'backups/'.$name;
        Storage::disk('local')->put($relative, (string) file_get_contents($absolutePath));

        $checksum = hash_file('sha256', Storage::disk('local')->path($relative)) ?: '';
        $bytes = (int) Storage::disk('local')->size($relative);
        $local = $this->localDestination();

        $snapshot = BackupSnapshot::query()->create([
            'kind' => $kind,
            'destination_id' => $local->id,
            'status' => BackupSnapshotStatus::Success,
            'path' => $relative,
            'remote_id' => $relative,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'manifest' => $manifest,
            'retained_until' => now()->addDays(BackupSettings::clampDays($retainDays)),
        ]);

        $this->replicate($snapshot, $absolutePath);

        return $snapshot->fresh() ?? $snapshot;
    }

    public function gzipFile(string $source, string $destination): void
    {
        $raw = file_get_contents($source);
        if ($raw === false) {
            throw new RuntimeException('Cannot read backup source.');
        }

        file_put_contents($destination, gzencode($raw, 6));
    }

    public function gunzipTo(string $source, string $destination): void
    {
        $raw = file_get_contents($source);
        if ($raw === false) {
            throw new RuntimeException('Cannot read compressed backup.');
        }

        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            throw new RuntimeException('Backup is not valid gzip.');
        }

        file_put_contents($destination, $decoded);
    }

    private function replicate(BackupSnapshot $local, string $absolutePath): void
    {
        $remotes = BackupDestination::query()
            ->where('enabled', true)
            ->where('kind', '!=', BackupDestinationKind::Local)
            ->get();

        foreach ($remotes as $destination) {
            if (! $destination->isConfigured()) {
                continue;
            }

            try {
                $transport = $this->transports->make($destination);
                $remoteId = $transport->put(basename((string) $local->path), $absolutePath);
                if (! $transport->has($remoteId)) {
                    throw new RuntimeException('Remote copy was not listed after upload.');
                }

                BackupSnapshot::query()->create([
                    'kind' => $local->kind,
                    'destination_id' => $destination->id,
                    'status' => BackupSnapshotStatus::Success,
                    'path' => $local->path,
                    'remote_id' => $remoteId,
                    'bytes' => $local->bytes,
                    'checksum' => $local->checksum,
                    'manifest' => $local->manifest,
                    'retained_until' => $local->retained_until,
                ]);
                $destination->rememberError(null);
            } catch (Throwable $exception) {
                Log::warning('backup.remote_failed', [
                    'destination' => $destination->id,
                    'kind' => $local->kind->value,
                ]);
                $destination->rememberError($exception->getMessage());
                BackupSnapshot::query()->create([
                    'kind' => $local->kind,
                    'destination_id' => $destination->id,
                    'status' => BackupSnapshotStatus::Failed,
                    'path' => $local->path,
                    'bytes' => $local->bytes,
                    'checksum' => $local->checksum,
                    'manifest' => $local->manifest,
                    'error' => 'Remote copy failed.',
                    'retained_until' => $local->retained_until,
                ]);
            }
        }
    }
}
