<?php

namespace Tests\Feature\Admin;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebhookEndpointManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_only_webhook_managers_can_access_endpoint_management(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)->get(route('admin.webhooks.index'))->assertForbidden();
    }

    public function test_admin_can_create_a_signed_https_webhook_endpoint(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.webhooks')
            ->set('name', 'Accounting connector')
            ->set('url', 'https://accounting.example.test/hooks/approvedesk')
            ->set('events', [WebhookEvent::WorkflowCompleted->value])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('newSecret', fn (?string $secret): bool => is_string($secret) && strlen($secret) === 64);

        $endpoint = WebhookEndpoint::query()->where('name', 'Accounting connector')->firstOrFail();
        $this->assertSame(['workflow.completed'], $endpoint->events);
        $this->assertSame($admin->id, $endpoint->created_by);
        $this->assertNotSame('webhook-secret', $endpoint->getRawOriginal('secret'));
    }

    public function test_endpoint_rejects_non_https_and_local_urls(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::admin.webhooks')
            ->set('name', 'Unsafe connector')
            ->set('url', 'http://localhost/webhook')
            ->set('events', [WebhookEvent::SubmissionCreated->value])
            ->call('save')
            ->assertHasErrors('url');
    }
}
