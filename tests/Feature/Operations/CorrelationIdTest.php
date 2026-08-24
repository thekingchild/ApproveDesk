<?php

namespace Tests\Feature\Operations;

use App\Support\CorrelationId;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        CorrelationId::clear();
    }

    public function test_response_includes_a_generated_correlation_id(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader(CorrelationId::HEADER);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,128}$/', (string) $response->headers->get(CorrelationId::HEADER));
    }

    public function test_incoming_correlation_id_is_echoed(): void
    {
        $this->withHeader(CorrelationId::HEADER, 'ops-test-correlation-1')
            ->get(route('login'))
            ->assertOk()
            ->assertHeader(CorrelationId::HEADER, 'ops-test-correlation-1');
    }

    public function test_malformed_correlation_id_is_replaced(): void
    {
        $response = $this->withHeader(CorrelationId::HEADER, 'bad id')
            ->get(route('login'))
            ->assertOk();

        $this->assertNotSame('bad id', $response->headers->get(CorrelationId::HEADER));
    }

    public function test_structured_log_channel_writes_json(): void
    {
        $path = storage_path('logs/structured.log');
        if (is_file($path)) {
            unlink($path);
        }

        Log::shareContext(['correlation_id' => 'ops-json-1']);
        Log::channel('structured')->info('ops.structured.test', ['ok' => true]);

        $this->assertFileExists($path);
        $line = trim((string) file_get_contents($path));
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ops.structured.test', $decoded['message']);
    }
}
