<?php

namespace Tests\Browser\Concerns;

use Laravel\Dusk\Browser;

trait ChecksAccessibility
{
    protected function assertNoSeriousAccessibilityViolations(Browser $browser, string $page): void
    {
        $axe = file_get_contents(base_path('node_modules/axe-core/axe.min.js'));
        $this->assertIsString($axe, 'axe-core must be installed before browser tests run.');

        $browser->driver->executeScript($axe);
        $results = $browser->driver->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            axe.run(document, {
                resultTypes: ['violations'],
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] },
            }).then(done).catch(error => done({ error: error.message }));
        JS);

        $this->assertIsArray($results, "{$page}: axe did not return a result.");
        $this->assertArrayNotHasKey('error', $results, "{$page}: axe failed to scan the page.");

        $violations = collect($results['violations'] ?? [])
            ->whereIn('impact', ['critical', 'serious'])
            ->map(fn (array $violation): string => sprintf(
                '%s (%s): %s [%s]',
                $violation['id'],
                $violation['impact'],
                $violation['help'],
                collect($violation['nodes'])->pluck('target')->flatten()->implode(', '),
            ))
            ->values()
            ->all();

        $this->assertSame([], $violations, $page." has critical or serious accessibility violations:\n".implode("\n", $violations));
    }
}
