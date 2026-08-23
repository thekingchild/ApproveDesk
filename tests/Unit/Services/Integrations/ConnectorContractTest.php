<?php

namespace Tests\Unit\Services\Integrations;

use App\Contracts\Integrations\Connector;
use App\Enums\ConnectorKey;
use App\Models\IntegrationAction;
use App\Models\Submission;
use App\Services\Integrations\ConnectorRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_every_connector_implements_the_contract_and_never_calls_a_live_provider(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $registry = app(ConnectorRegistry::class);
        $submission = Submission::factory()->create();
        $action = IntegrationAction::factory()->create();

        foreach (ConnectorKey::cases() as $key) {
            $connector = $registry->get($key);

            $this->assertInstanceOf(Connector::class, $connector);
            $this->assertSame($key, $connector->key());
            $this->assertNotSame('', $connector->label());
            $this->assertNotEmpty($connector->credentialFields());
            $this->assertNotEmpty($connector->supportedActions());

            $emptyHealth = $connector->healthCheck([]);
            $this->assertFalse($emptyHealth->ok, $key->value.' should fail health without credentials');

            $credentials = $this->credentialsFor($key);
            $this->assertTrue(
                $connector->healthCheck($credentials)->ok,
                $key->value.' should pass health with valid-looking credentials',
            );

            $result = $connector->execute($action, $submission, $credentials);
            $this->assertTrue($result->ok, $key->value.' execute should succeed against the fake HTTP client');
        }

        Http::assertSentCount(count(ConnectorKey::cases()));
    }

    /**
     * @return array<string, string>
     */
    private function credentialsFor(ConnectorKey $key): array
    {
        return match ($key) {
            ConnectorKey::Http => ['url' => 'https://hooks.example.test/http', 'secret' => 's'],
            ConnectorKey::Teams => ['webhook_url' => 'https://hooks.example.test/teams'],
            ConnectorKey::Slack => ['webhook_url' => 'https://hooks.example.test/slack'],
            ConnectorKey::GoogleDrive => ['access_token' => 'ya29.test', 'folder_id' => 'root'],
            ConnectorKey::SharePoint => [
                'access_token' => 'sp-token',
                'site_url' => 'https://contoso.sharepoint.com/sites/docs',
            ],
            ConnectorKey::Mail => ['access_token' => 'mail-token', 'from' => 'ops@example.test'],
        };
    }
}
