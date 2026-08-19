<?php

namespace Tests\Unit\Support;

use App\Support\RequestFieldKey;
use PHPUnit\Framework\TestCase;

class RequestFieldKeyTest extends TestCase
{
    public function test_it_slugs_a_label(): void
    {
        $this->assertSame('leave_reason', RequestFieldKey::uniqueFromLabel('Leave reason', [], 0));
    }

    public function test_it_suffixes_when_the_slug_is_taken(): void
    {
        $fields = [
            ['key' => 'leave_reason'],
            ['key' => ''],
        ];

        $this->assertSame('leave_reason_2', RequestFieldKey::uniqueFromLabel('Leave reason', $fields, 1));
    }
}
