<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_and_revoke_a_scoped_api_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole('submitter');

        $component = Livewire::actingAs($user)
            ->test('pages::settings.api-tokens')
            ->set('tokenName', 'Finance export')
            ->set('abilities', ['submissions:read', 'submissions:write'])
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('newToken', fn (?string $token): bool => is_string($token) && str_contains($token, '|'));

        $token = $user->tokens()->firstOrFail();
        $this->assertSame(['submissions:read', 'submissions:write'], $token->abilities);

        $component->call('revoke', $token->id)->assertHasNoErrors();

        $this->assertNull(PersonalAccessToken::query()->find($token->id));
    }
}
