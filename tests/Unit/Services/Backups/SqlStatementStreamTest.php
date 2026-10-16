<?php

namespace Tests\Unit\Services\Backups;

use App\Services\Backups\BackupRecorder;
use App\Services\Backups\SqlStatementStream;
use App\Support\LongRunningOperation;
use RuntimeException;
use Tests\TestCase;

/** RV-OPS-03: dumps are read and (de)compressed in chunks, not whole. */
class SqlStatementStreamTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_statements_split_only_on_semicolons_outside_quotes(): void
    {
        $path = $this->write(<<<'SQL'
-- ApproveDesk database snapshot
PRAGMA foreign_keys=OFF;
CREATE TABLE "notes" (id INTEGER, body TEXT);
INSERT INTO `notes` (`id`, `body`) VALUES (1, 'semi; colon');
INSERT INTO `notes` (`id`, `body`) VALUES (2, 'it''s
two lines; still one');
-- a comment between statements
INSERT INTO `notes` (`id`, `body`) VALUES (3, '-- not a comment')
SQL);

        $statements = iterator_to_array(SqlStatementStream::statements($path), false);

        $this->assertSame([
            'PRAGMA foreign_keys=OFF;',
            'CREATE TABLE "notes" (id INTEGER, body TEXT);',
            "INSERT INTO `notes` (`id`, `body`) VALUES (1, 'semi; colon');",
            "INSERT INTO `notes` (`id`, `body`) VALUES (2, 'it''s\ntwo lines; still one');",
            "INSERT INTO `notes` (`id`, `body`) VALUES (3, '-- not a comment')",
        ], $statements);
    }

    public function test_mysql_dumps_honour_backslash_escapes(): void
    {
        $path = $this->write("SET FOREIGN_KEY_CHECKS=0;\nINSERT INTO `t` VALUES ('a\\'; b', 'c\\\\');\nSET FOREIGN_KEY_CHECKS=1;\n");

        $this->assertTrue(SqlStatementStream::usesBackslashEscapes($path));
        $this->assertSame(
            ['SET FOREIGN_KEY_CHECKS=0;', "INSERT INTO `t` VALUES ('a\\'; b', 'c\\\\');", 'SET FOREIGN_KEY_CHECKS=1;'],
            iterator_to_array(SqlStatementStream::statements($path), false),
        );
    }

    public function test_statements_spanning_read_chunks_are_reassembled(): void
    {
        $value = str_repeat("x';y", 400000); // ~1.6 MB, crosses the 1 MB read size
        $quoted = str_replace("'", "''", $value);
        $path = $this->write("PRAGMA foreign_keys=OFF;\nINSERT INTO t VALUES ('{$quoted}');\nSELECT 1;\n");

        $statements = iterator_to_array(SqlStatementStream::statements($path), false);

        $this->assertCount(3, $statements);
        $this->assertSame("INSERT INTO t VALUES ('{$quoted}');", $statements[1]);
    }

    public function test_gzip_round_trip_streams_and_rejects_plain_files(): void
    {
        $source = $this->write(str_repeat("INSERT INTO t VALUES (1);\n", 100000));
        $gzip = $this->track($source.'.gz');
        $back = $this->track($source.'.out');
        $recorder = app(BackupRecorder::class);

        $recorder->gzipFile($source, $gzip);
        $this->assertSame("\x1f\x8b", (string) file_get_contents($gzip, false, null, 0, 2));
        $recorder->gunzipTo($gzip, $back);
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $back));

        $this->expectException(RuntimeException::class);
        $recorder->gunzipTo($source, $back);
    }

    public function test_fatal_guard_does_nothing_without_a_fatal_error_or_once_disarmed(): void
    {
        $ran = 0;
        $guard = LongRunningOperation::onFatal(function () use (&$ran): void {
            $ran++;
        });

        $guard->handleShutdown(); // the request ended without a fatal error
        $this->assertSame(0, $ran);

        $guard->disarm();
        $guard->handleShutdown();
        $this->assertSame(0, $ran);
    }

    public function test_fatal_guard_recovers_after_an_out_of_memory_crash(): void
    {
        $marker = $this->track(sys_get_temp_dir().'/ad-fatal-'.uniqid('', true));
        $script = sprintf(
            'require %s; App\Support\LongRunningOperation::onFatal(function () { file_put_contents(%s, "recovered"); }); $x = str_repeat("x", 64 * 1024 * 1024);',
            var_export(base_path('vendor/autoload.php'), true),
            var_export($marker, true),
        );

        exec(escapeshellarg(PHP_BINARY).' -d memory_limit=16M -r '.escapeshellarg($script).' 2>&1', $output, $exitCode);

        $this->assertNotSame(0, $exitCode, implode("\n", $output));
        $this->assertSame('recovered', @file_get_contents($marker));
    }

    private function write(string $contents): string
    {
        $path = $this->track(sys_get_temp_dir().'/ad-sql-'.uniqid('', true).'.sql');
        file_put_contents($path, $contents);

        return $path;
    }

    private function track(string $path): string
    {
        $this->files[] = $path;

        return $path;
    }
}
