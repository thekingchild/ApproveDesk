<?php

namespace Tests\Feature\Api\V1;

use App\Models\RequestType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
    }

    public function test_request_type_schemas_require_a_read_scope(): void
    {
        Sanctum::actingAs($this->submitter, ['approvals:act']);
        $this->getJson('/api/v1/request-types')->assertForbidden();
    }

    public function test_published_request_type_schema_is_listed(): void
    {
        Sanctum::actingAs($this->submitter, ['request-types:read']);

        $response = $this->getJson('/api/v1/request-types');
        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains(RequestType::GENERAL_SLUG));
    }

    public function test_idempotency_key_replays_the_same_create_response(): void
    {
        Sanctum::actingAs($this->submitter, ['submissions:write', 'submissions:read']);

        $payload = ['type' => 'memo', 'title' => 'Idempotent memo'];

        $first = $this->postJson('/api/v1/submissions', $payload, ['Idempotency-Key' => 'create-1']);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/submissions', $payload, ['Idempotency-Key' => 'create-1']);
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Submission::query()->where('title', 'Idempotent memo')->count());
    }

    public function test_idempotency_key_conflict_returns_409(): void
    {
        Sanctum::actingAs($this->submitter, ['submissions:write', 'submissions:read']);

        $this->postJson('/api/v1/submissions', ['type' => 'memo', 'title' => 'One'], ['Idempotency-Key' => 'same'])
            ->assertCreated();

        $this->postJson('/api/v1/submissions', ['type' => 'memo', 'title' => 'Two'], ['Idempotency-Key' => 'same'])
            ->assertStatus(409);
    }

    public function test_submissions_index_supports_cursor_pagination(): void
    {
        Sanctum::actingAs($this->submitter, ['submissions:read']);
        Submission::factory()->for($this->submitter, 'submitter')->count(3)->create();

        $this->getJson('/api/v1/submissions?cursor=&per_page=2')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_openapi_document_is_public(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('info.title', 'ApproveDesk API');
    }
}
