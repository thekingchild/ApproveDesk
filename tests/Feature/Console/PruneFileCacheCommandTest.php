<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/** RV-PERF-19: expired file cache entries are removed by a daily prune. */
class PruneFileCacheCommandTest extends TestCase
{
    public function test_only_expired_entries_are_deleted(): void
    {
        $directory = sys_get_temp_dir().'/ad-file-cache-'.uniqid('', true);
        mkdir($directory.'/ab/cd', 0777, true);
        config(['cache.stores.file.path' => $directory]);

        file_put_contents($directory.'/ab/cd/expired', (time() - 60).serialize('old'));
        file_put_contents($directory.'/ab/cd/fresh', (time() + 3600).serialize('new'));
        file_put_contents($directory.'/ab/cd/forever', '9999999999'.serialize('kept'));
        file_put_contents($directory.'/.gitignore', "*\n");

        try {
            $this->artisan('cache:prune-file-cache')->assertSuccessful();

            $this->assertFileDoesNotExist($directory.'/ab/cd/expired');
            $this->assertFileExists($directory.'/ab/cd/fresh');
            $this->assertFileExists($directory.'/ab/cd/forever');
            $this->assertFileExists($directory.'/.gitignore');
        } finally {
            exec('rm -rf '.escapeshellarg($directory));
        }
    }
}
