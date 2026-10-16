<?php

namespace App\Services\Backups;

use Generator;
use RuntimeException;

/**
 * Reads an ApproveDesk SQL dump one statement at a time, so restoring a
 * large backup never holds the whole dump in memory (RV-OPS-03).
 *
 * Statements end at a semicolon outside quotes. Quoted text may contain
 * semicolons and newlines; quotes are escaped by doubling ('') and, in
 * MySQL dumps, with a backslash. Comment lines between statements are skipped.
 */
final class SqlStatementStream
{
    private const CHUNK_BYTES = 1048576;

    /** MySQL dumps start with SET FOREIGN_KEY_CHECKS; SQLite ones with PRAGMA. */
    public static function usesBackslashEscapes(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the database dump.');
        }

        $head = (string) fread($handle, 512);
        fclose($handle);

        return stripos($head, 'SET FOREIGN_KEY_CHECKS') !== false;
    }

    /** @return Generator<int, string> */
    public static function statements(string $path, ?bool $backslashEscapes = null): Generator
    {
        $backslashEscapes ??= self::usesBackslashEscapes($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the database dump.');
        }

        try {
            $pending = '';
            $current = '';
            $quote = null;

            while (true) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                $pending .= is_string($chunk) ? $chunk : '';
                $eof = feof($handle) || $chunk === false;
                $length = strlen($pending);
                // Keep one byte of look-ahead (doubled quotes, escapes) until the end.
                $limit = $eof ? $length : $length - 1;
                $i = 0;

                while ($i < $limit) {
                    if ($quote === null) {
                        if ($current === '') {
                            $i += strspn($pending, " \t\r\n", $i, $limit - $i);
                            if ($i >= $limit) {
                                break;
                            }

                            if ($pending[$i] === '-' && ($pending[$i + 1] ?? '') === '-') {
                                $newline = strpos($pending, "\n", $i);
                                if ($newline === false) {
                                    $i = $eof ? $length : $i;

                                    break;
                                }
                                $i = $newline + 1;

                                continue;
                            }
                        }

                        $span = strcspn($pending, "';\"`", $i, $limit - $i);
                        $current .= substr($pending, $i, $span);
                        $i += $span;
                        if ($i >= $limit) {
                            break;
                        }

                        $char = $pending[$i];
                        $i++;
                        $current .= $char;

                        if ($char === ';') {
                            yield $current;
                            $current = '';
                        } else {
                            $quote = $char;
                        }

                        continue;
                    }

                    $stops = $quote.($backslashEscapes && $quote !== '`' ? '\\' : '');
                    $span = strcspn($pending, $stops, $i, $limit - $i);
                    $current .= substr($pending, $i, $span);
                    $i += $span;
                    if ($i >= $limit) {
                        break;
                    }

                    if ($pending[$i] === '\\') {
                        $current .= substr($pending, $i, 2);
                        $i += 2;

                        continue;
                    }

                    if (($pending[$i + 1] ?? '') === $quote) {
                        $current .= $quote.$quote;
                        $i += 2;

                        continue;
                    }

                    $current .= $quote;
                    $quote = null;
                    $i++;
                }

                $pending = (string) substr($pending, min($i, $length));

                if ($eof) {
                    if (trim($current.$pending) !== '') {
                        yield $current.$pending;
                    }

                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
