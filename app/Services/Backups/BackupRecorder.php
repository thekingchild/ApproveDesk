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
    private const CHUNK_BYTES = 1048576;

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
        $takenAt = now();
        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'bin';
        $stamp = $takenAt->timezone((string) config('app.timezone', 'UTC'))->format('Y-m-d-His');
        $name = $kind->value.'/'.$stamp.'.'.$extension;
        $suffix = 2;
        while (Storage::disk('local')->exists('backups/'.$name)) {
            $name = $kind->value.'/'.$stamp.'-'.$suffix.'.'.$extension;
            $suffix++;
        }
        Storage::disk('local')->makeDirectory('backups/'.$kind->value);
        $relative = 'backups/'.$name;
        if (! copy($absolutePath, Storage::disk('local')->path($relative))) {
            throw new RuntimeException('Cannot store the backup file.');
        }

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
            'taken_at' => $takenAt,
        ]);

        $this->replicate($snapshot, $absolutePath);

        return $snapshot->fresh() ?? $snapshot;
    }

    /** Compresses in chunks so a large dump is never held in memory (RV-OPS-03). */
    public function gzipFile(string $source, string $destination): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot read backup source.');
        }

        $out = gzopen($destination, 'wb6');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Cannot write compressed backup.');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
                    throw new RuntimeException('Cannot write compressed backup.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    public function gunzipTo(string $source, string $destination): void
    {
        $magic = @file_get_contents($source, false, null, 0, 2);
        if ($magic === false) {
            throw new RuntimeException('Cannot read compressed backup.');
        }

        // gzopen() would read a plain file transparently; insist on gzip.
        if ($magic !== "\x1f\x8b") {
            throw new RuntimeException('Backup is not valid gzip.');
        }

        $in = gzopen($source, 'rb');
        $out = @fopen($destination, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot read compressed backup.');
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Backup is not valid gzip.');
                }
                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
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
                    'taken_at' => $local->taken_at,
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
                    'taken_at' => $local->taken_at,
                ]);
            }
        }
    }
}
