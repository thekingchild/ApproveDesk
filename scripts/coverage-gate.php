<?php

/**
 * Coverage gate for CI — reads a PHPUnit clover XML report and fails when
 * line coverage across a requested application scope falls below the given
 * threshold. Clover may contain absolute or repository-relative filenames.
 *
 * Usage: php scripts/coverage-gate.php coverage.xml 80 [app|app/Services/WorkflowEngine.php]
 */
$report = $argv[1] ?? 'coverage.xml';
$threshold = (float) ($argv[2] ?? 80);
$scope = trim($argv[3] ?? 'app', '/');

if (! is_file($report)) {
    fwrite(STDERR, "Coverage report not found: {$report}\n");
    exit(1);
}

$xml = @simplexml_load_file($report);
if ($xml === false) {
    fwrite(STDERR, "Could not parse coverage report: {$report}\n");
    exit(1);
}

$files = $xml->xpath('//file') ?? [];

$statements = 0;
$covered = 0;

foreach ($files as $file) {
    $path = str_replace('\\', '/', (string) $file['name']);
    $appPosition = strpos($path, '/app/');
    $relativePath = $appPosition === false
        ? ltrim($path, '/')
        : substr($path, $appPosition + 1);

    $matchesScope = $scope === 'app'
        ? str_starts_with($relativePath, 'app/')
        : $relativePath === $scope;

    if (! $matchesScope) {
        continue;
    }

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
    fwrite(STDERR, "No executable statements found for [{$scope}] — coverage gate cannot run.\n");
    exit(1);
}

$percentage = round(($covered / $statements) * 100, 2);
printf('Line coverage (%s): %.2f%% (%d/%d statements) — threshold %.2f%%%s', $scope, $percentage, $covered, $statements, $threshold, PHP_EOL);

exit($percentage >= $threshold ? 0 : 1);
