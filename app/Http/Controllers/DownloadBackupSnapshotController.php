<?php

namespace App\Http\Controllers;

use App\Models\BackupSnapshot;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadBackupSnapshotController extends Controller
{
    public function __invoke(BackupSnapshot $snapshot): StreamedResponse
    {
        abort_unless(is_string($snapshot->path) && Storage::disk('local')->exists($snapshot->path), 404);

        return Storage::disk('local')->download(
            $snapshot->path,
            ($snapshot->kind->value).'-'.$snapshot->id.'.'.pathinfo($snapshot->path, PATHINFO_EXTENSION),
        );
    }
}
