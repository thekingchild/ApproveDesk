<?php

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ADR 0005 step 2: Alpine's CSP build evaluates one simple expression per
 * attribute, with no arrow functions, globals, optional chaining or @js()
 * strings it cannot unescape. Logic beyond that lives in
 * resources/js/alpine-components.js. This keeps new templates within the rules.
 */
class AlpineCspSafeTemplatesTest extends TestCase
{
    /** @var array<string, string> */
    private const RULES = [
        'arrow function' => '/=>/',
        'function keyword' => '/\bfunction\b/',
        'optional chaining' => '/\?\./',
        'nullish coalescing' => '/\?\?/',
        'template literal' => '/`/',
        'browser global' => '/\b(window|document|Intl|localStorage|navigator|console|JSON|Math|Date|setTimeout|alert|confirm)\b/',
        'async/await' => '/\b(async|await)\b/',
        'statement' => '/\b(if|for|while|let|const|var|return)\b\s*[\(\w{]/',
        'more than one statement' => '/;\s*\S/',
    ];

    public function test_alpine_attributes_stay_within_the_csp_build(): void
    {
        $problems = [];

        foreach ($this->views() as $path) {
            $text = (string) file_get_contents($path);
            preg_match_all('/<([a-zA-Z][\w:.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $text, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($tags as $tag) {
                $component = preg_match('/^(flux:|x-|livewire:)/', $tag[1][0]) === 1;
                preg_match_all('/(x-[\w:.-]+|@[a-z][\w.:-]*|:[a-z][\w-]*)\s*=\s*"([^"]*)"/s', $tag[2][0], $attributes, PREG_SET_ORDER);

                foreach ($attributes as [, $name, $value]) {
                    if (($name[0] === ':' && $component) || preg_match('/^@(if|else|endif|foreach|csrf|method|js|json|error|enderror|php)\b/', $name) === 1) {
                        continue;
                    }

                    $expression = preg_replace(['/@js\([^)]*\)|\{\{.*?\}\}|@json\([^)]*\)/s', "/'(?:\\\\.|[^'\\\\])*'/"], ['X', "''"], $value) ?? '';
                    if (str_contains($value, '@js(') && preg_match('/^x-data$/', $name) === 1) {
                        $problems[] = $this->where($path, $text, $tag[0][1])." {$name}: pass data with data-* attributes, not @js() (the CSP build cannot unescape it)";
                    }

                    foreach (self::RULES as $rule => $pattern) {
                        if (preg_match($pattern, $expression) === 1) {
                            $problems[] = $this->where($path, $text, $tag[0][1])." {$name}: {$rule}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Move this logic into resources/js/alpine-components.js:\n".implode("\n", $problems));
    }

    /** @return list<string> */
    private function views(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function where(string $path, string $text, int $offset): string
    {
        return str_replace(resource_path('views').'/', '', $path).':'.(substr_count(substr($text, 0, $offset), "\n") + 1);
    }
}
