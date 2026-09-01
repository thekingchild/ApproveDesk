<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;
use RuntimeException;

final class ApprovalReferenceSchema
{
    public const DEFAULT_PREFIX = 'SUB';

    public const DEFAULT_SEPARATOR = '-';

    public const DEFAULT_YEAR_PATTERN = 'yyyy';

    public const DEFAULT_MONTH_PATTERN = 'none';

    public const DEFAULT_DIGIT_LENGTH = 5;

    public const DEFAULT_GENERATION_MODE = 'sequential';

    public function prefix(): string
    {
        return (string) Setting::get('approvals.reference.prefix', self::DEFAULT_PREFIX);
    }

    public function separator(): string
    {
        return (string) Setting::get('approvals.reference.separator', self::DEFAULT_SEPARATOR);
    }

    public function yearPattern(): string
    {
        return (string) Setting::get('approvals.reference.year_pattern', self::DEFAULT_YEAR_PATTERN);
    }

    public function monthPattern(): string
    {
        return (string) Setting::get('approvals.reference.month_pattern', self::DEFAULT_MONTH_PATTERN);
    }

    public function digitLength(): int
    {
        return (int) Setting::get('approvals.reference.digit_length', (string) self::DEFAULT_DIGIT_LENGTH);
    }

    public function generationMode(): string
    {
        return (string) Setting::get('approvals.reference.generation_mode', self::DEFAULT_GENERATION_MODE);
    }

    public function preview(?CarbonInterface $at = null): string
    {
        return $this->format(1, $at);
    }

    public function format(int|string $number, ?CarbonInterface $at = null): string
    {
        $digits = $this->digitLength();
        $value = is_int($number) ? (string) $number : $number;

        if ($value === '' || ! ctype_digit($value) || strlen($value) > $digits || (int) $value >= (10 ** $digits)) {
            throw new RuntimeException('The sequential approval ID range for this format is exhausted.');
        }

        return implode($this->separator(), [
            ...$this->parts($at),
            str_pad($value, $digits, '0', STR_PAD_LEFT),
        ]);
    }

    public function numericPrefix(?CarbonInterface $at = null): string
    {
        return implode($this->separator(), $this->parts($at)).$this->separator();
    }

    public function counterScope(?CarbonInterface $at = null): string
    {
        return $this->digitLength().':'.$this->numericPrefix($at);
    }

    /** @return list<string> */
    private function parts(?CarbonInterface $at = null): array
    {
        $at ??= now();
        $parts = [$this->prefix()];

        if ($this->yearPattern() === 'yy') {
            $parts[] = $at->format('y');
        } elseif ($this->yearPattern() === 'yyyy') {
            $parts[] = $at->format('Y');
        }

        if ($this->monthPattern() === 'mm') {
            $parts[] = $at->format('m');
        } elseif ($this->monthPattern() === 'mmm') {
            $parts[] = strtoupper($at->format('M'));
        }

        return $parts;
    }
}
