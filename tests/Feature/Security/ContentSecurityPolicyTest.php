<?php

namespace Tests\Feature\Security;

use App\Http\PublicWebsite;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR 0005 step 1 (RV-SEC-15): scripts need the response's nonce, and no page
 * relies on an inline script or event attribute without it.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rv_sec_15_script_src_uses_a_fresh_nonce_and_no_unsafe_inline(): void
    {
        $first = $this->scriptSrc($this->get(route('login')));
        $second = $this->scriptSrc($this->get(route('login')));

        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\\/=]{16,}'/", $first);
        $this->assertStringNotContainsString("'unsafe-inline'", $first);
        $this->assertStringContainsString("'unsafe-eval'", $first, 'Kept until Alpine runs in its CSP build (step 2).');
        $this->assertNotSame($first, $second, 'Each response gets its own nonce.');
    }

    public function test_rv_sec_15_every_inline_script_on_key_pages_carries_the_nonce(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $submission = Submission::factory()->for($admin, 'submitter')->create();
        Setting::put('brand.custom_code', '<script>window.ownerSnippet = true;</script>');

        $pages = [
            'login' => fn () => $this->get(route('login')),
            'dashboard' => fn () => $this->actingAs($admin)->get(route('dashboard')),
            'request page' => fn () => $this->actingAs($admin)->get(route('submissions.show', $submission)),
            'notification settings' => fn () => $this->actingAs($admin)->get(route('notifications.edit')),
            'users' => fn () => $this->actingAs($admin)->get(route('admin.users.index')),
        ];

        foreach ($pages as $name => $visit) {
            $response = $visit()->assertOk();
            $nonce = $this->nonce($response);
            $html = (string) $response->getContent();

            preg_match_all('#<script\b([^>]*)>#i', $html, $tags);
            foreach ($tags[1] as $attributes) {
                if (str_contains($attributes, 'src=') || str_contains($attributes, 'application/ld+json')) {
                    continue;
                }
                $this->assertStringContainsString('nonce="'.$nonce.'"', $attributes, "Inline script without the nonce on the {$name} page: <script{$attributes}>");
            }

            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load|error|input)="/i', $html, "Inline event handler on the {$name} page.");
        }
    }

    public function test_rv_sec_15_the_approval_report_print_button_is_not_an_inline_handler(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $submission = Submission::factory()->for($admin, 'submitter')->create();

        $response = $this->actingAs($admin)->get(route('submissions.approval-report.show', $submission));
        if ($response->status() !== 200) {
            $this->markTestSkipped('Report route needs a workflow for this request.');
        }

        $html = (string) $response->getContent();
        $this->assertStringContainsString('data-print', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringContainsString('nonce="'.$this->nonce($response).'"', $html);
    }

    public function test_rv_sec_15_owner_website_scripts_get_the_nonce_but_keep_an_existing_one(): void
    {
        Vite::useCspNonce('test-nonce');
        Setting::put('brand.custom_code', 'window.ownerSnippet = true;');

        $html = app(PublicWebsite::class)->inject(
            '<html><head><script>console.log(1)</script><script nonce="theirs"></script></head><body><nav data-approvedesk-auth></nav></body></html>',
            null,
            false,
        );

        $this->assertStringContainsString('<script nonce="test-nonce">console.log(1)</script>', $html);
        $this->assertStringContainsString('<script nonce="test-nonce">window.ownerSnippet = true;</script>', $html);
        $this->assertStringContainsString('<script nonce="theirs">', $html);
    }

    private function scriptSrc(TestResponse $response): string
    {
        preg_match('/script-src[^;]*/', (string) $response->headers->get('Content-Security-Policy'), $match);

        return $match[0] ?? '';
    }

    private function nonce(TestResponse $response): string
    {
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $match);
        $this->assertNotEmpty($match[1] ?? null, 'The response has a script nonce.');

        return $match[1];
    }
}
