<?php

namespace Tests\Feature\Security;

use App\Actions\Settings\UpdateCustomCodeAction;
use App\Models\Setting;
use App\Models\SystemOwner;
use App\Models\User;
use App\Support\Branding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** ADR 0005 step 2: report-only strict policy, the report endpoint, and the owner origin allow-list. */
class CspReportOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_adr_0005_the_strict_policy_is_reported_only_while_csp_safe_is_on(): void
    {
        $response = $this->get(route('login'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'), 'Off by default.');
        $this->assertStringContainsString('report-uri /csp-report', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Reporting-Endpoints'), 'The Reporting API needs HTTPS; plain HTTP uses report-uri.');
        $secure = $this->get(str_replace('http://', 'https://', route('login')));
        $this->assertStringContainsString('csp-endpoint=', (string) $secure->headers->get('Reporting-Endpoints'));
        $this->assertStringContainsString('report-to csp-endpoint', (string) $secure->headers->get('Content-Security-Policy'));

        config(['livewire.csp_safe' => true]);
        $response = $this->get(route('login'));

        $enforced = (string) $response->headers->get('Content-Security-Policy');
        $reportOnly = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("'unsafe-eval'", $enforced, 'Enforcement is unchanged until step 3.');
        $this->assertStringNotContainsString("'unsafe-eval'", $reportOnly);
        $this->assertStringContainsString("'nonce-", $reportOnly);
        $this->assertStringContainsString('report-uri /csp-report', $reportOnly);
    }

    public function test_adr_0005_reports_are_logged_without_query_strings(): void
    {
        $log = storage_path('logs/csp-'.now()->format('Y-m-d').'.log');
        @unlink($log);

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], (string) json_encode(['csp-report' => [
            'document-uri' => 'https://approvedesk.test/submissions/7?signature=secret',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'eval',
        ]]))->assertNoContent();

        $written = (string) file_get_contents($log);
        $this->assertStringContainsString('csp.violation', $written);
        $this->assertStringContainsString('https://approvedesk.test/submissions/7', $written);
        $this->assertStringContainsString('"blocked":"eval"', $written);
        $this->assertStringNotContainsString('signature=secret', $written);
        @unlink($log);
    }

    public function test_adr_0005_the_report_endpoint_rejects_oversized_or_malformed_bodies_and_is_throttled(): void
    {
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 9000))->assertStatus(413);
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);

        RateLimiter::clear('csp-report');
        $statuses = [];
        for ($i = 0; $i < 32; $i++) {
            $statuses[] = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], '[]')->status();
        }
        $this->assertContains(429, $statuses);
    }

    public function test_adr_0005_owner_origin_list_replaces_scraping_hosts_from_the_code(): void
    {
        Setting::put('brand.custom_code', '<!-- old: https://legacy.example.com --><script src="https://www.googletagmanager.com/gtag/js"></script>');

        // Before the owner saves a list, upgrades keep working.
        $this->assertSame(['https://legacy.example.com', 'https://www.googletagmanager.com'], Branding::customCodeOrigins());

        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        // Narrowing the list (and leaving the code alone) needs no step-up.
        app(UpdateCustomCodeAction::class)->execute(
            $owner,
            (string) Setting::get('brand.custom_code'),
            "https://www.googletagmanager.com/\nhttp://insecure.example.com\nhttps://evil.example.com/path",
        );

        $this->assertSame(['https://www.googletagmanager.com'], Branding::customCodeOrigins(), 'Only valid HTTPS origins are kept; a host in a comment no longer counts.');
        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringNotContainsString('legacy.example.com', $csp);

        app(UpdateCustomCodeAction::class)->execute($owner, (string) Setting::get('brand.custom_code'), '');
        $this->assertSame([], Branding::customCodeOrigins(), 'An empty list stays empty instead of falling back to scraping.');
    }

    public function test_adr_0005_only_the_custom_code_permission_changes_the_origin_list(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        try {
            app(UpdateCustomCodeAction::class)->execute($admin, null, 'https://attacker.example.com');
            $this->fail('An administrator changed the custom-code origin list.');
        } catch (AuthorizationException) {
        }

        $this->assertNull(Setting::get(Branding::CUSTOM_CODE_ORIGINS));
    }
}
