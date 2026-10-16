<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\HandleApiIdempotency;
use App\Models\ApiIdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * RV-BUG-27: two requests with the same Idempotency-Key must not both run.
 * The race is simulated by sending the second request while the first is
 * still inside the handler (the critical section of the old check-then-insert).
 */
class IdempotencyRaceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_a_concurrent_request_with_the_same_key_does_not_run_the_handler_twice(): void
    {
        $middleware = app(HandleApiIdempotency::class);
        $runs = 0;
        $concurrent = null;

        $response = $middleware->handle($this->request('race-1'), function () use ($middleware, &$runs, &$concurrent): Response {
            $runs++;
            // A second identical request arrives while the first is still running.
            $concurrent = $middleware->handle($this->request('race-1'), function () use (&$runs): Response {
                $runs++;

                return response()->json(['id' => 2], 201);
            });

            return response()->json(['id' => 1], 201);
        });

        $this->assertSame(1, $runs);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertInstanceOf(Response::class, $concurrent);
        $this->assertSame(409, $concurrent->getStatusCode());
        $this->assertSame('1', $concurrent->headers->get('Retry-After'));

        // Once the first finishes, a retry replays its stored response.
        $replay = $middleware->handle($this->request('race-1'), fn (): Response => throw new RuntimeException('must not run'));
        $this->assertSame(201, $replay->getStatusCode());
        $this->assertSame(['id' => 1], json_decode((string) $replay->getContent(), true));
    }

    public function test_a_failed_request_releases_its_key_so_the_client_can_retry(): void
    {
        $middleware = app(HandleApiIdempotency::class);

        $error = $middleware->handle($this->request('retry-1'), fn (): Response => response()->json(['message' => 'boom'], 500));
        $this->assertSame(500, $error->getStatusCode());

        try {
            $middleware->handle($this->request('retry-1'), fn (): Response => throw new RuntimeException('crash'));
            $this->fail('The exception should propagate.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ApiIdempotencyKey::query()->count());

        $ok = $middleware->handle($this->request('retry-1'), fn (): Response => response()->json(['id' => 3], 201));
        $this->assertSame(201, $ok->getStatusCode());
    }

    public function test_an_abandoned_claim_can_be_taken_over_after_it_goes_stale(): void
    {
        DB::table('api_idempotency_keys')->insert([
            'user_id' => $this->user->id,
            'key' => 'stale-1',
            'method' => 'POST',
            'path' => '/api/v1/submissions',
            'request_hash' => hash('sha256', 'POST|api/v1/submissions|{"title":"x"}'),
            'status_code' => null,
            'response_body' => null,
            'created_at' => now()->subMinutes(HandleApiIdempotency::STALE_AFTER_MINUTES + 1),
            'updated_at' => now()->subMinutes(HandleApiIdempotency::STALE_AFTER_MINUTES + 1),
        ]);

        $response = app(HandleApiIdempotency::class)->handle($this->request('stale-1'), fn (): Response => response()->json(['id' => 4], 201));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(201, ApiIdempotencyKey::query()->where('key', 'stale-1')->value('status_code'));
    }

    public function test_an_overlong_key_is_refused_instead_of_failing_on_insert(): void
    {
        $response = app(HandleApiIdempotency::class)->handle($this->request(str_repeat('k', 256)), fn (): Response => throw new RuntimeException('must not run'));

        $this->assertSame(422, $response->getStatusCode());
    }

    private function request(string $key): Request
    {
        $request = Request::create('/api/v1/submissions', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"title":"x"}');
        $request->headers->set('Idempotency-Key', $key);
        $request->setUserResolver(fn (): User => $this->user);

        return $request;
    }
}
