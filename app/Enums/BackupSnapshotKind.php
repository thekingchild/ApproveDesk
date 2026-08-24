<?php

namespace App\Enums;

enum BackupSnapshotKind: string
{
    case Database = 'database';
    case Files = 'files';
}
