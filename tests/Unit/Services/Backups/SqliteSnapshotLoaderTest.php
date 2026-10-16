<?php

namespace Tests\Unit\Services\Backups;

use App\Services\Backups\SqliteSnapshotLoader;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

class SqliteSnapshotLoaderTest extends TestCase
{
    public function test_mysql_foreign_key_set_statements_are_stripped_and_the_dump_loads(): void
    {
        $sql = <<<'SQL'
-- ApproveDesk database snapshot
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
INSERT INTO `users` (`id`, `name`) VALUES (1, 'Ada');
SET FOREIGN_KEY_CHECKS=1;
SQL;
        $path = sys_get_temp_dir().'/ad-sqlite-load-'.uniqid('', true).'.sqlite';
        $dump = $path.'.sql';
        file_put_contents($dump, $sql);

        try {
            app(SqliteSnapshotLoader::class)->loadSqlDumpFile($dump, $path);
            $pdo = new PDO('sqlite:'.$path);
            $this->assertSame('Ada', $pdo->query('select name from users')->fetchColumn());
        } finally {
            foreach ([$path, $dump] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_empty_dump_is_refused(): void
    {
        $dump = sys_get_temp_dir().'/ad-empty-'.uniqid('', true).'.sql';
        file_put_contents($dump, "SET FOREIGN_KEY_CHECKS=0;\nSET FOREIGN_KEY_CHECKS=1;\n");

        try {
            $this->expectException(InvalidArgumentException::class);
            app(SqliteSnapshotLoader::class)->loadSqlDumpFile($dump, sys_get_temp_dir().'/ad-empty.sqlite');
        } finally {
            unlink($dump);
        }
    }
}
