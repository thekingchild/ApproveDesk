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

        try {
            app(SqliteSnapshotLoader::class)->loadSqlDump($sql, $path);
            $pdo = new PDO('sqlite:'.$path);
            $this->assertSame('Ada', $pdo->query('select name from users')->fetchColumn());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_empty_dump_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SqliteSnapshotLoader::class)->loadSqlDump("SET FOREIGN_KEY_CHECKS=0;\nSET FOREIGN_KEY_CHECKS=1;\n", sys_get_temp_dir().'/ad-empty.sqlite');
    }
}
