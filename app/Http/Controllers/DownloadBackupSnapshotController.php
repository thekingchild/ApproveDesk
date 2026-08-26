<?php

namespace App\Http\Controllers;

use App\Actions\Backups\MaterializeBackupSnapshotAction;
use App\Models\BackupSnapshot;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadBackupSnapshotController extends Controller
{
    public function __invoke(BackupSnapshot $snapshot, MaterializeBackupSnapshotAction $materialize): BinaryFileResponse
    {
        $absolute = $materialize->execute($snapshot);

        return response()->download(
            $absolute,
            ($snapshot->downloadName()),
        );
    }
}
