<?php

namespace Tests\Unit\Services\Users;

use App\Services\Users\UserSpreadsheetImportGuard;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class UserSpreadsheetImportGuardTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_xlsx_with_more_than_512_zip_entries_is_rejected(): void
    {
        $path = $this->tempPath();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        for ($i = 0; $i < 513; $i++) {
            $zip->addFromString('pad'.$i.'.xml', 'x');
        }

        $zip->close();

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_xlsx_with_more_than_eight_worksheets_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Sheet1');
        $spreadsheet->getActiveSheet()->fromArray([[
            'name', 'email', 'department', 'manager_email', 'phone', 'roles',
        ]]);

        for ($i = 2; $i <= 9; $i++) {
            $spreadsheet->createSheet()->setTitle('Sheet'.$i);
        }

        $path = $this->tempPath();
        (new Xlsx($spreadsheet))->save($path);

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_xlsx_with_dimension_beyond_row_or_column_limit_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['name', 'email', 'department', 'manager_email', 'phone', 'roles'],
            ['Ada Okonkwo', 'ada@example.com', '', '', '', ''],
        ]);
        $sheet->setCellValue('AG5002', 'x');

        $path = $this->tempPath();
        (new Xlsx($spreadsheet))->save($path);

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_xlsx_with_excessive_compression_ratio_is_rejected(): void
    {
        $payload = str_repeat('x', 100);
        $path = $this->writeRawZip([
            [
                'name' => 'xl/bomb.xml',
                'data' => $payload,
                'size' => 33_554_432,
                'comp_size' => 100,
            ],
        ]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $stat = $zip->statIndex(0);
        $zip->close();
        $this->assertIsArray($stat);
        $this->assertSame(100, $stat['comp_size']);
        $this->assertSame(33_554_432, $stat['size']);

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_xlsx_with_duplicate_entry_names_is_rejected(): void
    {
        $path = $this->writeRawZip([
            ['name' => 'xl/workbook.xml', 'data' => 'a'],
            ['name' => 'xl/workbook.xml', 'data' => 'b'],
        ]);

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_xlsx_with_path_traversal_entry_name_is_rejected(): void
    {
        $path = $this->writeRawZip([
            ['name' => '../xl/workbook.xml', 'data' => 'x'],
        ]);

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_xlsx_with_nul_in_entry_name_is_rejected(): void
    {
        $path = $this->writeRawZip([
            ['name' => "xl/sheet\0.xml", 'data' => 'x'],
        ]);

        $this->expectException(RuntimeException::class);
        UserSpreadsheetImportGuard::assertSafe($path);
    }

    public function test_valid_boundary_workbook_is_allowed(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['name', 'email', 'department', 'manager_email', 'phone', 'roles'],
            ['Ada Okonkwo', 'ada@example.com', 'Finance', '', '', 'submitter'],
        ]);

        $path = $this->tempPath();
        (new Xlsx($spreadsheet))->save($path);

        UserSpreadsheetImportGuard::assertSafe($path);

        $this->assertFileExists($path);
    }

    private function tempPath(string $suffix = '.xlsx'): string
    {
        $path = sys_get_temp_dir().'/usig-'.bin2hex(random_bytes(8)).$suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  list<array{name: string, data?: string, size?: int, comp_size?: int, method?: int, crc?: int}>  $files
     */
    private function writeRawZip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;

        foreach ($files as $file) {
            $name = $file['name'];
            $data = $file['data'] ?? '';
            $method = $file['method'] ?? 0;
            $crc = $file['crc'] ?? crc32($data);
            $compSize = $file['comp_size'] ?? strlen($data);
            $uncompSize = $file['size'] ?? strlen($data);
            $nameLen = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034B50,
                20,
                0,
                $method,
                0,
                0,
                $crc,
                $compSize,
                $uncompSize,
                $nameLen,
                0,
            );
            $cd = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                0,
                $method,
                0,
                0,
                $crc,
                $compSize,
                $uncompSize,
                $nameLen,
                0,
                0,
                0,
                0,
                0,
                $offset,
            );

            $local .= $localHeader.$name.$data;
            $central .= $cd.$name;
            $offset += strlen($localHeader) + $nameLen + strlen($data);
            $count++;
        }

        $eocd = pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            $offset,
            0,
        );

        $path = $this->tempPath();
        file_put_contents($path, $local.$central.$eocd);

        return $path;
    }
}
