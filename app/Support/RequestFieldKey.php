<?php

namespace App\Support;

use Illuminate\Support\Str;

final class RequestFieldKey
{
    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    public static function uniqueFromLabel(string $label, array $fields, int $currentIndex): string
    {
        $base = Str::snake(str_replace('-', '_', Str::slug($label))) ?: 'field';
        $base = substr($base, 0, 90);
        $key = $base;
        $suffix = 2;
        $used = [];

        foreach (array_values($fields) as $index => $field) {
            if ($index === $currentIndex) {
                continue;
            }

            $existing = trim((string) ($field['key'] ?? ''));

            if ($existing !== '') {
                $used[] = $existing;
            }
        }

        while (in_array($key, $used, true)) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }
}
