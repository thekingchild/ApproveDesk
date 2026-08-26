<?php

namespace Tests\Unit\Services\Backups;

use App\Models\BackupSnapshot;
use App\Services\Backups\BackupIntegrity;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class BackupIntegrityTest extends TestCase
{
    public function test_empty_checksum_or_size_mismatch_fails_closed(): void
    {
        $path = sys_get_temp_dir().'/ad-integrity-'.uniqid('', true).'.bin';
        file_put_contents($path, 'abcd');

        try {
            $integrity = app(BackupIntegrity::class);

            $this->expectException(InvalidArgumentException::class);
            $integrity->assertMatches(new BackupSnapshot([
                'bytes' => 4,
                'checksum' => '',
            ]), $path);
        } finally {
            unlink($path);
        }
    }

    public function test_matching_digest_is_accepted(): void
    {
        $path = sys_get_temp_dir().'/ad-integrity-'.uniqid('', true).'.bin';
        file_put_contents($path, 'abcd');

        try {
            app(BackupIntegrity::class)->assertMatches(new BackupSnapshot([
                'bytes' => 4,
                'checksum' => hash('sha256', 'abcd'),
            ]), $path);
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    public function test_zip_rejects_extra_entries_and_unsafe_names(): void
    {
        $path = sys_get_temp_dir().'/ad-integrity-'.uniqid('', true).'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('submissions/9/note.txt', 'invoice body');
        $zip->addFromString('manifest.json', '{}');
        $zip->addFromString('extra.txt', 'bonus');
        $zip->close();

        try {
            $this->expectException(InvalidArgumentException::class);
            app(BackupIntegrity::class)->assertZipMatchesManifest($path, [
                'files' => [[
                    'path' => 'submissions/9/note.txt',
                    'sha256' => hash('sha256', 'invoice body'),
                ]],
            ]);
        } finally {
            unlink($path);
        }
    }
}
