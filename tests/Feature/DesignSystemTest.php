<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_page_requires_authentication(): void
    {
        $this->get(route('help'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_open_help_and_product_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('Help centre')
            ->assertSee('Create a request')
            ->assertSee('Review approvals')
            ->assertSee('Control notifications')
            ->assertSee('Skip to main content')
            ->assertSee(route('notifications.edit'), escape: false)
            ->assertDontSee('github.com/laravel/livewire-starter-kit', escape: false)
            ->assertDontSee('laravel.com/docs/starter-kits', escape: false);
    }

    public function test_design_system_reference_is_available_in_testing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('design-system'))
            ->assertOk()
            ->assertSee('ApproveDesk design system')
            ->assertSee('Typography')
            ->assertSee('Colour and status')
            ->assertSee('Responsive data table')
            ->assertSee('data-test="design-system-reference"', escape: false);
    }

    public function test_primary_pages_use_semantic_page_heading_and_product_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<h1 class="ad-page-title">Dashboard</h1>', escape: false)
            ->assertSee('Help')
            ->assertSee('Notifications')
            ->assertDontSee('Repository')
            ->assertDontSee('Documentation');
    }
}
