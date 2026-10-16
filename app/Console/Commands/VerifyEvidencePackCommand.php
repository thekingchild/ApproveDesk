<?php

namespace App\Console\Commands;

use App\Services\Audit\EvidencePackVerifier;
use Illuminate\Console\Command;

class VerifyEvidencePackCommand extends Command
{
    protected $signature = 'evidence:verify {path : Path to a downloaded evidence pack zip}';

    protected $description = 'Verify an evidence pack signature and file hashes.';

    public function handle(EvidencePackVerifier $verifier): int
    {
        $path = (string) $this->argument('path');
        $result = $verifier->verifyPath($path);

        if (! $result->ok) {
            $this->error($result->error);

            return self::FAILURE;
        }

        // Older packs only signed their file hashes: report them, never as "verified" (RV-SEC-21).
        if ($result->legacy) {
            $this->warn($result->message());

            return self::SUCCESS;
        }

        $this->info('Evidence pack is intact and its manifest signature is verified.');

        return self::SUCCESS;
    }
}
