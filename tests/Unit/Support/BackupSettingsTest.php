<?php

namespace Tests\Unit\Support;

use App\Support\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_used_when_settings_table_is_missing(): void
    {
        Schema::dropIfExists('settings');

        $this->assertSame('02:30', BackupSettings::databaseAt());
        $this->assertSame('02:45', BackupSettings::filesAt());
        $this->assertTrue(BackupSettings::databaseEnabled());
    }
}
