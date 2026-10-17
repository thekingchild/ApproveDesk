<?php

namespace Tests\Unit\Services\Users;

use App\Services\Users\UserImportService;
use RuntimeException;
use Tests\TestCase;

/** RV-LAR-17: CSV imports have the same 5,000-row limit as spreadsheets. */
class UserImportCsvRowLimitTest extends TestCase
{
    public function test_rv_lar_17_csv_with_5000_data_rows_is_read(): void
    {
        $this->assertCount(5000, app(UserImportService::class)->rows($this->csv(5000), 'csv'));
    }

    public function test_rv_lar_17_csv_over_the_limit_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(UserImportService::class)->rows($this->csv(5001), 'csv');
    }

    private function csv(int $dataRows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        $handle = fopen($path, 'wb');
        fwrite($handle, "name,email\n");
        for ($i = 1; $i <= $dataRows; $i++) {
            fwrite($handle, "Person {$i},person{$i}@example.com\n");
        }
        fclose($handle);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
