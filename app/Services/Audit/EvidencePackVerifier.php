<?php

namespace App\Services\Audit;

use App\DTOs\Audit\EvidencePackVerification;
use ZipArchive;

final class EvidencePackVerifier
{
    public function __construct(private readonly EvidencePackSigner $signer) {}

    public function verifyPath(string $path): EvidencePackVerification
    {
        if (! is_file($path)) {
            return new EvidencePackVerification(false, 'Evidence pack file was not found.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return new EvidencePackVerification(false, 'The file is not a readable evidence pack.');
        }

        $raw = $zip->getFromName('manifest.json');
        if (! is_string($raw) || $raw === '') {
            $zip->close();

            return new EvidencePackVerification(false, 'The pack is missing its manifest.');
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || ! isset($manifest['files'], $manifest['signature']) || ! is_array($manifest['files'])) {
            $zip->close();

            return new EvidencePackVerification(false, 'The manifest is not valid.');
        }

        /** @var array<string, string> $files */
        $files = $manifest['files'];
        $signature = (string) $manifest['signature'];

        if (! $this->signer->valid($files, $signature)) {
            $zip->close();

            return new EvidencePackVerification(false, 'The pack signature does not match.');
        }

        foreach ($files as $name => $expected) {
            $contents = $zip->getFromName((string) $name);
            if (! is_string($contents)) {
                $zip->close();

                return new EvidencePackVerification(false, 'Missing pack file: '.$name);
            }

            if (! hash_equals((string) $expected, hash('sha256', $contents))) {
                $zip->close();

                return new EvidencePackVerification(false, 'Hash mismatch for '.$name);
            }
        }

        $zip->close();

        return new EvidencePackVerification(true);
    }
}
