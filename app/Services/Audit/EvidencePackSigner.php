<?php

namespace App\Services\Audit;

final class EvidencePackSigner
{
    public function sign(array $files): string
    {
        return hash_hmac('sha256', $this->canonical($files), $this->secret());
    }

    public function valid(array $files, string $signature): bool
    {
        return hash_equals($this->sign($files), $signature);
    }

    /**
     * @param  array<string, string>  $files
     */
    public function canonical(array $files): string
    {
        ksort($files);

        return json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function secret(): string
    {
        return (string) config('app.key');
    }
}
