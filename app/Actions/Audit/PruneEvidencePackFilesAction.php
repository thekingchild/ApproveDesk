<?php

namespace App\Actions\Audit;

use Illuminate\Support\Facades\Storage;

/**
 * Every evidence-pack download builds a fresh zip and nothing reads it again,
 * so old zips are only disk usage. The evidence_packs row (manifest hash and
 * signature) and the export log are kept (RV-OPS-05).
 */
final class PruneEvidencePackFilesAction
{
    public const KEEP_DAYS = 30;

    public function execute(int $days = self::KEEP_DAYS): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('evidence') as $path) {
            if (! str_ends_with($path, '.zip') || $disk->lastModified($path) >= $cutoff) {
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        foreach ($disk->directories('evidence') as $directory) {
            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }

        return $deleted;
    }
}
