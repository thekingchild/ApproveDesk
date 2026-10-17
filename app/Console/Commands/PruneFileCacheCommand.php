<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Laravel's file cache only removes an expired entry when that key is read
 * again, so on shared hosting the cache directory grows without bound
 * (RV-PERF-19). Each entry starts with a 10-digit expiry timestamp.
 */
class PruneFileCacheCommand extends Command
{
    protected $signature = 'cache:prune-file-cache';

    protected $description = 'Delete expired entries from the file cache store.';

    public function handle(): int
    {
        $path = (string) config('cache.stores.file.path');
        if ($path === '' || ! is_dir($path)) {
            $this->info('No file cache directory to prune.');

            return self::SUCCESS;
        }

        $now = time();
        $deleted = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getFilename() === '.gitignore') {
                continue;
            }

            $handle = @fopen($file->getPathname(), 'rb');
            if ($handle === false) {
                continue;
            }
            $expiry = (string) fread($handle, 10);
            fclose($handle);

            // 9999999999 marks "forever"; anything non-numeric is not a cache entry.
            if (ctype_digit($expiry) && strlen($expiry) === 10 && (int) $expiry <= $now && @unlink($file->getPathname())) {
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} expired file cache entries.");

        return self::SUCCESS;
    }
}
