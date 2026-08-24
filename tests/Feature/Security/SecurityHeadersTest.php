<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_and_dashboard_send_security_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertNotFalse($csp);
        $this->assertStringContainsString("default-src 'self'", (string) $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $csp);

        $user = User::factory()->create()->assignRole('submitter');
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('Content-Security-Policy');
    }
}
