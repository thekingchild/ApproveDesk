<?php

namespace App\Services\Users;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class UserImportReadFilter implements IReadFilter
{
    private const COLUMNS = [
        'A' => true,
        'B' => true,
        'C' => true,
        'D' => true,
        'E' => true,
        'F' => true,
    ];

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row < 1 || $row > UserSpreadsheetImportGuard::MAX_ROWS) {
            return false;
        }

        return isset(self::COLUMNS[strtoupper($columnAddress)]);
    }
}
