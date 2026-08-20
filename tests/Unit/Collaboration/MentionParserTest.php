<?php

namespace Tests\Unit\Collaboration;

use App\Models\User;
use App\Services\Collaboration\MentionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_active_users_from_tokens_and_explicit_ids(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        User::factory()->create(['is_active' => false]);

        $ids = app(MentionParser::class)->userIds('Please see @{'.$alice->id.'}', [$bob->id, 999]);

        $this->assertEqualsCanonicalizing([$alice->id, $bob->id], $ids);
    }

    public function test_display_replaces_tokens_with_names(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $text = app(MentionParser::class)->display('Hello @{'.$user->id.'}', collect([$user]));

        $this->assertSame('Hello @Ada Lovelace', $text);
    }
}
