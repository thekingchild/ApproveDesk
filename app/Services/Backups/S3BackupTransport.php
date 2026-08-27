<?php

namespace App\Services\Backups;

use App\Models\BackupDestination;
use App\Support\OutboundDestination;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class S3BackupTransport implements BackupTransport
{
    public function __construct(
        private readonly BackupDestination $destination,
        private ?Filesystem $disk = null,
    ) {}

    public function put(string $name, string $absolutePath): string
    {
        $key = $this->prefix().$name;
        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read backup file.');
        }

        try {
            $this->disk()->put($key, $stream);
        } finally {
            fclose($stream);
        }

        if (! $this->disk()->exists($key)) {
            throw new RuntimeException('Remote object was not stored.');
        }

        return $key;
    }

    public function has(string $remoteId): bool
    {
        return $this->disk()->exists($remoteId);
    }

    public function fetch(string $remoteId, string $destination): void
    {
        $contents = $this->disk()->get($remoteId);
        if ($contents === null) {
            throw new RuntimeException('Remote object is missing.');
        }

        file_put_contents($destination, $contents);
    }

    public function delete(string $remoteId): void
    {
        $this->disk()->delete($remoteId);
    }

    public function test(): bool
    {
        $this->disk()->files($this->prefix());

        return true;
    }

    private function disk(): Filesystem
    {
        if ($this->disk instanceof Filesystem) {
            return $this->disk;
        }

        $credentials = $this->destination->credentials();
        $endpoint = $credentials['endpoint'] ?? null;
        if (is_string($endpoint) && trim($endpoint) !== '') {
            app(OutboundDestination::class)->validate($endpoint, [
                'allow_private' => (bool) config('backups.allow_private_s3_endpoint'),
                'allow_nonstandard_port' => (bool) config('backups.allow_private_s3_endpoint'),
            ]);
        }

        $this->disk = Storage::build([
            'driver' => 's3',
            'key' => $credentials['key'] ?? '',
            'secret' => $credentials['secret'] ?? '',
            'region' => $credentials['region'] ?? 'auto',
            'bucket' => $credentials['bucket'] ?? '',
            'endpoint' => $credentials['endpoint'] ?? null,
            'use_path_style_endpoint' => ($credentials['path_style'] ?? '0') === '1',
            'throw' => true,
        ]);

        return $this->disk;
    }

    private function prefix(): string
    {
        $prefix = trim($this->destination->credentials()['prefix'] ?? 'approvedesk-backups', '/');

        return $prefix === '' ? '' : $prefix.'/';
    }
}
