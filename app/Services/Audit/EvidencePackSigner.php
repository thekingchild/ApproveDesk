<?php

namespace App\Services\Audit;

final class EvidencePackSigner
{
    /** Manifest format whose signature covers every manifest field, not only the file hashes (RV-SEC-21). */
    public const MANIFEST_FORMAT = 2;

    /**
     * Signs the whole manifest (format, generated_at, submission_id, files)
     * with a key derived from APP_KEY, so editing any manifest field breaks
     * the signature (RV-SEC-21).
     *
     * @param  array<string, mixed>  $manifest  the manifest without its signature
     */
    public function signManifest(array $manifest): string
    {
        return hash_hmac('sha256', $this->canonical($manifest), $this->manifestKey($this->secrets()[0]));
    }

    /**
     * @param  array<string, mixed>  $manifest  the manifest without its signature
     */
    public function validManifest(array $manifest, string $signature): bool
    {
        $canonical = $this->canonical($manifest);

        foreach ($this->secrets() as $secret) {
            if (hash_equals(hash_hmac('sha256', $canonical, $this->manifestKey($secret)), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legacy (format 1) packs signed only the file-hash map with the raw APP_KEY.
     * Kept so packs downloaded before RV-SEC-21 can still be checked.
     *
     * @param  array<string, string>  $files
     */
    public function validLegacy(array $files, string $signature): bool
    {
        $canonical = $this->canonical($files);

        foreach ($this->secrets() as $secret) {
            if (hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function canonical(array $payload): string
    {
        return json_encode($this->sorted($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function sorted(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sorted($value);
            }
        }

        return $payload;
    }

    /** Domain-separated key so the evidence signature cannot be confused with other APP_KEY uses. */
    private function manifestKey(string $secret): string
    {
        return hash_hmac('sha256', 'approvedesk.evidence-pack.manifest.v2', $secret, true);
    }

    /**
     * The current APP_KEY first, then APP_PREVIOUS_KEYS, so packs survive a key rotation.
     *
     * @return non-empty-list<string>
     */
    private function secrets(): array
    {
        $previous = config('app.previous_keys', []);

        return [
            (string) config('app.key'),
            ...array_values(array_filter(array_map('strval', is_array($previous) ? $previous : []))),
        ];
    }
}
