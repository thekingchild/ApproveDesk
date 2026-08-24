<?php

namespace App\Enums;

enum BackupSnapshotStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Verified = 'verified';
    case Promoted = 'promoted';
}
