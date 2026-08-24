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

        $this->info('Evidence pack is intact.');

        return self::SUCCESS;
    }
}
