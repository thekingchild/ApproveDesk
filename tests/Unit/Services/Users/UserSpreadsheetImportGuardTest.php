<?php

namespace Tests\Unit\Services\Users;

use App\Services\Roles\RoleAssignmentService;
use App\Services\Users\ActiveUserLimitService;
use App\Services\Users\GrantableRoleService;
use App\Services\Users\UserImportService;
use App\Services\Users\UserSpreadsheetImportGuard;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class UserSpreadsheetImportGuardTest extends TestCase
{
    /** @var list<string> */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_valid_xlsx_is_accepted(): void
    {
        $path = $this->writePhpSpreadsheetXlsx([
            ['name', 'email', 'department', 'manager_email', 'phone', 'roles'],
            ['Ada Admin', 'ada@corp.example', '', '', '', 'submitter'],
        ]);

        app(UserSpreadsheetImportGuard::class)->assertSafe($path);
        $this->addToAssertionCount(1);
    }

    public function test_valid_xls_is_accepted(): void
    {
        $path = $this->tempPath('xls');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['name', 'email', 'department', 'manager_email', 'phone', 'roles'],
            ['Xls Xavier', 'xavier@corp.example', '', '', '', 'submitter'],
        ]);
        (new Xls($spreadsheet))->save($path);

        app(UserSpreadsheetImportGuard::class)->assertSafe($path);
        $this->addToAssertionCount(1);

        $rows = app(UserImportService::class)->rows($path, 'xls');
        $this->assertSame('Xls Xavier', $rows[0]['name']);
        $this->assertSame('xavier@corp.example', $rows[0]['email']);
    }

    public function test_too_many_zip_entries_are_rejected(): void
    {
        $path = $this->tempPath('xlsx');
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        for ($i = 0; $i <= UserSpreadsheetImportGuard::MAX_ZIP_ENTRIES; $i++) {
            $zip->addFromString('e'.$i.'.xml', '');
        }
        $zip->close();

        $this->assertRejected($path);
    }

    public function test_claimed_hundred_to_one_compression_ratio_is_rejected(): void
    {
        $workbook = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:F2"/><sheetData/></worksheet>';
        $types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>';

        $path = $this->rawZip([
            '[Content_Types].xml' => ['content' => $types],
            'xl/workbook.xml' => [
                'content' => $workbook,
                'uncompressed' => (strlen($workbook) * UserSpreadsheetImportGuard::MAX_RATIO) + 1,
            ],
            'xl/worksheets/sheet1.xml' => ['content' => $sheet],
        ]);

        $this->assertRejected($path);
    }

    public function test_claimed_oversized_entry_is_rejected(): void
    {
        $path = $this->rawZip([
            'xl/workbook.xml' => ['content' => '<workbook/>', 'uncompressed' => UserSpreadsheetImportGuard::MAX_ENTRY + 1],
        ]);

        $this->assertRejected($path);
    }

    public function test_encrypted_package_is_rejected(): void
    {
        $path = $this->rawZip([
            'EncryptedPackage' => ['content' => 'cipher'],
            'EncryptionInfo' => ['content' => 'hdr'],
        ]);

        $this->assertRejected($path);
    }

    public function test_path_traversal_and_duplicate_names_are_rejected(): void
    {
        $this->assertRejected($this->rawZip([
            ['name' => '../xl/workbook.xml', 'content' => '<workbook/>'],
        ]));

        $this->assertRejected($this->rawZip([
            ['name' => 'xl/workbook.xml', 'content' => '<workbook/>'],
            ['name' => 'xl/workbook.xml', 'content' => '<workbook/>'],
        ]));
    }

    public function test_too_many_sheets_are_rejected(): void
    {
        $sheets = '';
        for ($i = 1; $i <= UserSpreadsheetImportGuard::MAX_SHEETS + 1; $i++) {
            $sheets .= '<sheet name="S'.$i.'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
        }

        $path = $this->minimalXlsx(
            workbook: '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>',
        );

        $this->assertRejected($path);
    }

    public function test_oversized_worksheet_dimension_is_rejected(): void
    {
        $path = $this->minimalXlsx(
            sheet: '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:A5002"/><sheetData/></worksheet>',
        );

        $this->assertRejected($path);
    }

    public function test_too_many_shared_strings_are_rejected(): void
    {
        $count = UserSpreadsheetImportGuard::MAX_SHARED_STRINGS + 1;
        $path = $this->minimalXlsx(
            extra: [
                'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$count.'" uniqueCount="'.$count.'"></sst>',
            ],
        );

        $this->assertRejected($path);
    }

    public function test_malformed_workbook_xml_is_rejected_with_generic_message(): void
    {
        $path = $this->minimalXlsx(
            workbook: '<?xml version="1.0"?><workbook><sheets><sheet name="Sheet1"',
        );

        $this->assertRejected($path);

        try {
            app(UserImportService::class)->rows($path, 'xlsx');
            $this->fail('Malformed workbook XML should not be imported.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame(__('This spreadsheet cannot be imported.'), $exception->getMessage());
        }
    }

    public function test_rejected_zip_never_reaches_spreadsheet_load(): void
    {
        $path = $this->rawZip([
            'EncryptedPackage' => ['content' => 'cipher'],
        ]);

        $loads = 0;
        $service = new class(app(GrantableRoleService::class), app(RoleAssignmentService::class), app(ActiveUserLimitService::class), app(UserSpreadsheetImportGuard::class), function () use (&$loads): void {
            $loads++;
        },
        ) extends UserImportService {

            public function __construct(
                GrantableRoleService $roles,
                RoleAssignmentService $assignments,
                ActiveUserLimitService $limits,
                UserSpreadsheetImportGuard $spreadsheetGuard,
                private readonly \Closure $onLoad,
            ) {
                parent::__construct($roles, $assignments, $limits, $spreadsheetGuard);
            }

            protected function loadSpreadsheet(IReader $reader, string $path): Spreadsheet
            {
                ($this->onLoad)();

                return parent::loadSpreadsheet($reader, $path);
            }
        };

        try {
            $service->rows($path, 'xlsx');
            $this->fail('Rejected workbook should not be loaded.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('This spreadsheet cannot be imported.'), $exception->getMessage());
        }

        $this->assertSame(0, $loads);
    }

    public function test_service_reads_xlsx_rows_without_to_array(): void
    {
        $path = $this->writePhpSpreadsheetXlsx([
            ['name', 'email', 'department', 'manager_email', 'phone', 'roles'],
            ['Exel Ella', 'ella@corp.example', '', '', '', 'submitter'],
        ]);

        $rows = app(UserImportService::class)->rows($path, 'xlsx');

        $this->assertSame('Exel Ella', $rows[0]['name']);
        $this->assertSame('ella@corp.example', $rows[0]['email']);
        $this->assertSame('submitter', $rows[0]['roles']);
    }

    private function assertRejected(string $path): void
    {
        try {
            app(UserSpreadsheetImportGuard::class)->assertSafe($path);
            $this->fail('Expected spreadsheet to be rejected.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame(__('This spreadsheet cannot be imported.'), $exception->getMessage());
        }
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function writePhpSpreadsheetXlsx(array $rows): string
    {
        $path = $this->tempPath('xlsx');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function minimalXlsx(?string $workbook = null, ?string $sheet = null, array $extra = []): string
    {
        $workbook ??= '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $sheet ??= '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:F2"/><sheetData/></worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'xl/workbook.xml' => $workbook,
            'xl/worksheets/sheet1.xml' => $sheet,
            ...$extra,
        ];

        $entries = [];
        foreach ($files as $name => $content) {
            $entries[] = ['name' => $name, 'content' => $content];
        }

        return $this->rawZip($entries);
    }

    /**
     * Stored ZIP with optional claimed uncompressed sizes so tests stay tiny.
     *
     * @param  list<array{name?: string, content: string, uncompressed?: int}>|array<string, array{content: string, uncompressed?: int}>  $files
     */
    private function rawZip(array $files): string
    {
        $path = $this->tempPath('xlsx');
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;

        foreach ($files as $key => $spec) {
            $name = $spec['name'] ?? (is_string($key) ? $key : '');
            $content = $spec['content'];
            $crc = crc32($content) & 0xFFFFFFFF;
            $length = strlen($content);
            $uncompressed = $spec['uncompressed'] ?? $length;
            $nameLength = strlen($name);

            $localHeader = "PK\x03\x04"
                .pack('v', 20)
                .pack('v', 0)
                .pack('v', 0)
                .pack('v', 0)
                .pack('v', 0)
                .pack('V', $crc)
                .pack('V', $length)
                .pack('V', $uncompressed)
                .pack('v', $nameLength)
                .pack('v', 0)
                .$name
                .$content;

            $central .= "PK\x01\x02"
                .pack('v', 20)
                .pack('v', 20)
                .pack('v', 0)
                .pack('v', 0)
                .pack('v', 0)
                .pack('v', 0)
                .pack('V', $crc)
                .pack('V', $length)
                .pack('V', $uncompressed)
                .pack('v', $nameLength)
                .pack('v', 0)
                .pack('v', 0)
                .pack('v', 0)
                .pack('v', 0)
                .pack('V', 0)
                .pack('V', $offset)
                .$name;

            $offset += strlen($localHeader);
            $local .= $localHeader;
            $count++;
        }

        $eocd = "PK\x05\x06"
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', $count)
            .pack('v', $count)
            .pack('V', strlen($central))
            .pack('V', strlen($local))
            .pack('v', 0);

        file_put_contents($path, $local.$central.$eocd);

        return $path;
    }

    private function tempPath(string $suffix): string
    {
        $path = sys_get_temp_dir().'/ad-ss-'.uniqid('', true).'.'.$suffix;
        $this->temps[] = $path;

        return $path;
    }
}
