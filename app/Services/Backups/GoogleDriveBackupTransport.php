<?php

namespace App\Services\Backups;

use App\Models\BackupDestination;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleDriveBackupTransport implements BackupTransport
{
    public function __construct(private readonly BackupDestination $destination) {}

    public function put(string $name, string $absolutePath): string
    {
        $token = $this->accessToken();
        $folder = $this->destination->credentials()['folder_id'] ?? '';
        $contents = (string) file_get_contents($absolutePath);
        $metadata = json_encode([
            'name' => $name,
            'parents' => [$folder],
        ], JSON_THROW_ON_ERROR);

        $response = Http::timeout(30)
            ->withToken($token)
            ->attach('metadata', $metadata, 'metadata.json', ['Content-Type' => 'application/json'])
            ->attach('file', $contents, $name)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive upload failed.');
        }

        $id = (string) $response->json('id');
        if ($id === '') {
            throw new RuntimeException('Google Drive did not return a file id.');
        }

        return $id;
    }

    public function has(string $remoteId): bool
    {
        $response = Http::timeout(15)
            ->withToken($this->accessToken())
            ->get('https://www.googleapis.com/drive/v3/files/'.$remoteId, ['fields' => 'id']);

        return $response->successful();
    }

    public function fetch(string $remoteId, string $destination): void
    {
        $response = Http::timeout(30)
            ->withToken($this->accessToken())
            ->get('https://www.googleapis.com/drive/v3/files/'.$remoteId, ['alt' => 'media']);

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive download failed.');
        }

        file_put_contents($destination, $response->body());
    }

    public function delete(string $remoteId): void
    {
        Http::timeout(15)
            ->withToken($this->accessToken())
            ->delete('https://www.googleapis.com/drive/v3/files/'.$remoteId);
    }

    public function test(): bool
    {
        $folder = $this->destination->credentials()['folder_id'] ?? '';
        $response = Http::timeout(15)
            ->withToken($this->accessToken())
            ->get('https://www.googleapis.com/drive/v3/files', [
                'q' => sprintf("'%s' in parents and trashed = false", $folder),
                'pageSize' => 1,
                'fields' => 'files(id)',
            ]);

        return $response->successful();
    }

    private function accessToken(): string
    {
        $credentials = $this->destination->credentials();
        if (($credentials['refresh_token'] ?? '') !== '') {
            $response = Http::timeout(15)->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $credentials['client_id'] ?? '',
                'client_secret' => $credentials['client_secret'] ?? '',
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);

            $token = (string) $response->json('access_token');
            if ($token === '') {
                throw new RuntimeException('Google Drive token refresh failed.');
            }

            return $token;
        }

        throw new RuntimeException('Google Drive is not configured for unattended access.');
    }
}
