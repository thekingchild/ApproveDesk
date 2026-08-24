<?php

namespace Tests\Feature\Security;

use App\Models\WebhookEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotateWebhookSecretsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_command_rotates_webhook_secrets(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'old-secret-value-that-is-long-enough']);
        $previous = $endpoint->secret;

        $this->artisan('secrets:rotate-webhooks', ['endpoint' => $endpoint->id])
            ->assertSuccessful();

        $this->assertNotSame($previous, $endpoint->fresh()->secret);
        $this->assertSame(64, strlen((string) $endpoint->fresh()->secret));
    }
}
