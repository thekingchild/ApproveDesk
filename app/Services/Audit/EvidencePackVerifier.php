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

        try {
            return $this->verifyZip($zip);
        } finally {
            $zip->close();
        }
    }

    private function verifyZip(ZipArchive $zip): EvidencePackVerification
    {
        $raw = $zip->getFromName('manifest.json');
        if (! is_string($raw) || $raw === '') {
            return new EvidencePackVerification(false, 'The pack is missing its manifest.');
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || ! isset($manifest['files'], $manifest['signature']) || ! is_array($manifest['files']) || ! is_string($manifest['signature'])) {
            return new EvidencePackVerification(false, 'The manifest is not valid.');
        }

        $files = [];
        foreach ($manifest['files'] as $name => $hash) {
            if (! is_string($hash) || $name === 'manifest.json') {
                return new EvidencePackVerification(false, 'The manifest is not valid.');
            }
            $files[(string) $name] = $hash;
        }

        $signature = $manifest['signature'];
        $legacy = ! array_key_exists('format', $manifest);

        if ($legacy) {
            if (! $this->signer->validLegacy($files, $signature)) {
                return new EvidencePackVerification(false, 'The pack signature does not match.');
            }
        } else {
            $signed = $manifest;
            unset($signed['signature']);
            if (! $this->signer->validManifest($signed, $signature)) {
                return new EvidencePackVerification(false, 'The pack signature does not match.');
            }
        }

        // Every entry must be the manifest or a listed file, each exactly once, so
        // nothing can be slipped into an "intact" pack (RV-SEC-21).
        $seen = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || ($name !== 'manifest.json' && ! array_key_exists($name, $files))) {
                return new EvidencePackVerification(false, 'Unexpected file in pack: '.(is_string($name) ? $name : '#'.$index));
            }
            if (isset($seen[$name])) {
                return new EvidencePackVerification(false, 'Duplicate file in pack: '.$name);
            }
            $seen[$name] = true;
        }

        foreach ($files as $name => $expected) {
            $contents = $zip->getFromName($name);
            if (! is_string($contents)) {
                return new EvidencePackVerification(false, 'Missing pack file: '.$name);
            }

            if (! hash_equals($expected, hash('sha256', $contents))) {
                return new EvidencePackVerification(false, 'Hash mismatch for '.$name);
            }
        }

        return new EvidencePackVerification(true, legacy: $legacy);
    }
}
