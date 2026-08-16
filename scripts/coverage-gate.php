<?php

/**
 * Coverage gate for CI — reads a PHPUnit clover XML report and fails when
 * line coverage across app/ falls below the given threshold.
 *
 * Usage: php scripts/coverage-gate.php coverage.xml 80
 */

$report = $argv[1] ?? 'coverage.xml';
$threshold = (float) ($argv[2] ?? 80);

if (! is_file($report)) {
    fwrite(STDERR, "Coverage report not found: {$report}\n");
    exit(1);
}

$xml = @simplexml_load_file($report);
if ($xml === false) {
    fwrite(STDERR, "Could not parse coverage report: {$report}\n");
    exit(1);
}

$metrics = $xml->xpath('//file[starts-with(@name, "app/")]') ?? [];

$statements = 0;
$covered = 0;

foreach ($metrics as $file) {
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt') {
            $statements++;
            if ((int) $line['count'] > 0) {
                $covered++;
            }
        }
    }
}

if ($statements === 0) {
    fwrite(STDERR, "No executable statements found under app/ — coverage gate cannot run.\n");
    exit(1);
}

$percentage = round(($covered / $statements) * 100, 2);
printf("Line coverage (app/): %.2f%% (%d/%d statements) — threshold %.2f%%%s", $percentage, $covered, $statements, $threshold, PHP_EOL);

exit($percentage >= $threshold ? 0 : 1);
