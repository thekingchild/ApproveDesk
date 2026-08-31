<?php

namespace App\Services\Users;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Preflight for XLS/XLSX user imports. Fail closed with a generic message.
 * ZIP inspection is stat-only; XML is streamed. Never simplexml_load_string.
 */
final class UserSpreadsheetImportGuard
{
    public const MAX_ZIP_ENTRIES = 512;

    public const MAX_UNCOMPRESSED = 64 * 1024 * 1024;

    public const MAX_ENTRY = 32 * 1024 * 1024;

    public const MAX_RATIO = 100;

    public const MAX_SHEETS = 8;

    public const MAX_ROWS = 5001;

    public const MAX_COLS = 32;

    public const MAX_CELLS = 160032;

    public const MAX_SHARED_STRINGS = 100000;

    public const MAX_SHARED_STRINGS_XML = 16 * 1024 * 1024;

    private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const WORKBOOK_XML_MAX = 1024 * 1024;

    public function assertSafe(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->reject();
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->reject();
        }

        $magic = fread($handle, 8);
        fclose($handle);

        if (! is_string($magic) || $magic === '') {
            $this->reject();
        }

        if (str_starts_with($magic, 'PK')) {
            $this->assertSafeXlsx($path);

            return;
        }

        if (str_starts_with($magic, self::OLE_MAGIC)) {
            $this->assertSafeXls($path);

            return;
        }

        $this->reject();
    }

    private function assertSafeXlsx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->reject();
        }

        try {
            $names = $this->inspectZipStats($zip);
            $this->inspectXlsxXml($zip, $names);
        } catch (\Throwable) {
            $this->reject();
        } finally {
            $zip->close();
        }
    }

    private function assertSafeXls(string $path): void
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $sheets = $reader->listWorksheetInfo($path);
        } catch (\Throwable) {
            $this->reject();
        }

        if ($sheets === [] || count($sheets) > self::MAX_SHEETS) {
            $this->reject();
        }

        foreach ($sheets as $sheet) {
            $rows = $sheet['totalRows'];
            $cols = $sheet['totalColumns'];
            if ($cols === 0) {
                $cols = $sheet['lastColumnIndex'] + 1;
            }
            $this->assertSheetBounds($rows, $cols);
        }
    }

    /**
     * @return list<string>
     */
    private function inspectZipStats(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $this->reject();
        }

        $seen = [];
        $uncompressed = 0;
        $compressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                $this->reject();
            }

            $name = $stat['name'];
            $this->assertSafeZipName($name);
            if (isset($seen[$name])) {
                $this->reject();
            }
            $seen[$name] = true;

            $base = strtolower(basename(str_replace('\\', '/', $name)));
            if ($base === 'encryptedpackage' || $base === 'encryptioninfo') {
                $this->reject();
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $size = $stat['size'];
            $compSize = $stat['comp_size'];
            if ($size < 0 || $compSize < 0) {
                $this->reject();
            }
            if ($size > self::MAX_ENTRY) {
                $this->reject();
            }
            if ($compSize === 0 && $size > 0) {
                $this->reject();
            }
            if ($compSize > 0 && $compSize <= intdiv(PHP_INT_MAX, self::MAX_RATIO) && $size > $compSize * self::MAX_RATIO) {
                $this->reject();
            }
            if ($uncompressed > self::MAX_UNCOMPRESSED - $size) {
                $this->reject();
            }
            $uncompressed += $size;
            if ($compressed > PHP_INT_MAX - $compSize) {
                $this->reject();
            }
            $compressed += $compSize;

            if (strcasecmp($name, 'xl/sharedStrings.xml') === 0 && $size > self::MAX_SHARED_STRINGS_XML) {
                $this->reject();
            }
        }

        if ($compressed === 0 && $uncompressed > 0) {
            $this->reject();
        }
        if ($compressed > 0 && $compressed <= intdiv(PHP_INT_MAX, self::MAX_RATIO) && $uncompressed > $compressed * self::MAX_RATIO) {
            $this->reject();
        }

        return array_keys($seen);
    }

    /**
     * @param  list<string>  $names
     */
    private function inspectXlsxXml(ZipArchive $zip, array $names): void
    {
        $workbook = $this->findEntry($names, 'xl/workbook.xml');
        if ($workbook === null) {
            $this->reject();
        }

        $sheetCount = 0;
        $this->readXmlEntry($zip, $workbook, self::WORKBOOK_XML_MAX, function (XMLReader $reader) use (&$sheetCount): void {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'sheet') {
                    continue;
                }
                $sheetCount++;
                if ($sheetCount > self::MAX_SHEETS) {
                    $this->reject();
                }
            }
        });

        if ($sheetCount < 1) {
            $this->reject();
        }

        $shared = $this->findEntry($names, 'xl/sharedStrings.xml');
        if ($shared !== null) {
            $this->inspectSharedStrings($zip, $shared);
        }

        $worksheets = [];
        foreach ($names as $name) {
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name) === 1) {
                $worksheets[] = $name;
            }
        }
        if ($worksheets === [] || count($worksheets) > self::MAX_SHEETS) {
            $this->reject();
        }

        foreach ($worksheets as $worksheet) {
            $this->inspectWorksheet($zip, $worksheet);
        }
    }

    private function inspectSharedStrings(ZipArchive $zip, string $name): void
    {
        $si = 0;
        $this->readXmlEntry($zip, $name, self::MAX_SHARED_STRINGS_XML, function (XMLReader $reader) use (&$si): void {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'sst') {
                    $this->assertCountAttribute($reader->getAttribute('count'));
                    $this->assertCountAttribute($reader->getAttribute('uniqueCount'));
                }
                if ($reader->localName === 'si') {
                    $si++;
                    if ($si > self::MAX_SHARED_STRINGS) {
                        $this->reject();
                    }
                }
            }
        });
    }

    private function inspectWorksheet(ZipArchive $zip, string $name): void
    {
        $this->readXmlEntry($zip, $name, self::MAX_ENTRY, function (XMLReader $reader): void {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'dimension' || $reader->localName === 'mergeCell') {
                    $ref = $reader->getAttribute('ref');
                    if (is_string($ref) && $ref !== '') {
                        $this->assertRefWithinLimits($ref);
                    }
                }
                if ($reader->localName === 'c') {
                    $cellRef = $reader->getAttribute('r');
                    if (is_string($cellRef) && $cellRef !== '') {
                        $this->assertRefWithinLimits($cellRef);
                    }
                }
            }
        });
    }

    /**
     * @param  callable(XMLReader): void  $consume
     */
    private function readXmlEntry(ZipArchive $zip, string $name, int $maxBytes, callable $consume): void
    {
        $tmp = $this->copyZipEntryCapped($zip, $name, $maxBytes);
        try {
            $uri = stream_get_meta_data($tmp)['uri'] ?? null;
            if (! is_string($uri) || $uri === '') {
                $this->reject();
            }

            $previous = libxml_use_internal_errors(true);
            try {
                $reader = new XMLReader;
                if ($reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT) !== true) {
                    $this->reject();
                }

                try {
                    $consume($reader);
                } finally {
                    $reader->close();
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } finally {
            fclose($tmp);
        }
    }

    /**
     * @return resource
     */
    private function copyZipEntryCapped(ZipArchive $zip, string $name, int $maxBytes)
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            $this->reject();
        }

        $tmp = tmpfile();
        if ($tmp === false) {
            fclose($stream);
            $this->reject();
        }

        $copied = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    $this->reject();
                }
                if ($chunk === '') {
                    break;
                }
                $length = strlen($chunk);
                if ($length > $maxBytes || $copied > $maxBytes - $length) {
                    $this->reject();
                }
                $copied += $length;
                if (fwrite($tmp, $chunk) === false) {
                    $this->reject();
                }
            }
        } finally {
            fclose($stream);
        }

        if (rewind($tmp) === false) {
            $this->reject();
        }

        return $tmp;
    }

    /**
     * @param  list<string>  $names
     */
    private function findEntry(array $names, string $want): ?string
    {
        foreach ($names as $name) {
            if (strcasecmp($name, $want) === 0) {
                return $name;
            }
        }

        return null;
    }

    private function assertSafeZipName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            $this->reject();
        }

        $trimmed = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
        if ($trimmed === '') {
            $this->reject();
        }

        foreach (explode('/', $trimmed) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                $this->reject();
            }
        }
    }

    private function assertCountAttribute(?string $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1 || strlen($value) > 6 || (int) $value > self::MAX_SHARED_STRINGS) {
            $this->reject();
        }
    }

    private function assertRefWithinLimits(string $ref): void
    {
        $ref = strtoupper(str_replace('$', '', trim($ref)));
        if ($ref === '') {
            $this->reject();
        }

        $parts = explode(':', $ref, 2);
        $maxCol = 1;
        $maxRow = 1;
        $minCol = PHP_INT_MAX;
        $minRow = PHP_INT_MAX;

        foreach ($parts as $part) {
            if (preg_match('/^([A-Z]+)(\d+)$/', $part, $match) !== 1) {
                $this->reject();
            }
            if (strlen($match[2]) > 6) {
                $this->reject();
            }
            $col = $this->columnIndex($match[1]);
            $row = (int) $match[2];
            if ($row < 1) {
                $this->reject();
            }
            $maxCol = max($maxCol, $col);
            $maxRow = max($maxRow, $row);
            $minCol = min($minCol, $col);
            $minRow = min($minRow, $row);
        }

        $this->assertSheetBounds($maxRow, $maxCol);
        $this->assertSheetBounds($maxRow - $minRow + 1, $maxCol - $minCol + 1);
    }

    private function columnIndex(string $letters): int
    {
        if ($letters === '' || strlen($letters) > 3) {
            $this->reject();
        }

        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($letters[$i]);
            if ($ord < 65 || $ord > 90) {
                $this->reject();
            }
            $digit = $ord - 64;
            if ($index > intdiv(PHP_INT_MAX - $digit, 26)) {
                $this->reject();
            }
            $index = $index * 26 + $digit;
        }

        return $index;
    }

    private function assertSheetBounds(int $rows, int $cols): void
    {
        if ($rows < 0 || $cols < 0) {
            $this->reject();
        }
        if ($rows > self::MAX_ROWS || $cols > self::MAX_COLS) {
            $this->reject();
        }
        if ($rows > 0 && $cols > 0 && $rows > intdiv(self::MAX_CELLS, $cols)) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new RuntimeException(__('This spreadsheet cannot be imported.'));
    }
}
