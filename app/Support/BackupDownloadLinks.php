<?php

namespace App\Support;

use App\Models\BackupSnapshot;
use Illuminate\Support\Facades\URL;

/**
 * Signed links to a backup archive (RV-ARC-18). The download route re-checks the
 * permission; the short lifetime only stops a copied link from working for long.
 */
final class BackupDownloadLinks
{
    public const DOWNLOAD_MINUTES = 5;

    public static function download(BackupSnapshot $snapshot): string
    {
        return URL::temporarySignedRoute(
            'admin.backups.download',
            now()->addMinutes(self::DOWNLOAD_MINUTES),
            ['snapshot' => $snapshot],
        );
    }
}
