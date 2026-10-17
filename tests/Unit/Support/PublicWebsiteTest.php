<?php

namespace Tests\Unit\Support;

use App\Http\PublicWebsite;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private PublicWebsite $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/approvedesk-website-'.uniqid('', true);
        mkdir($this->root.'/css', 0755, true);
        file_put_contents($this->root.'/index.html', <<<'HTML'
<!DOCTYPE html>
<html><body>
<header><nav data-approvedesk-auth></nav></header>
<h1>Stop chasing approvals in chat threads</h1>
</body></html>
HTML);
        file_put_contents($this->root.'/css/app.css', 'body{margin:0}');

        $this->website = new PublicWebsite($this->root);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/css/app.css');
        @rmdir($this->root.'/css');
        @unlink($this->root.'/index.html');
        @unlink($this->root.'/.env');
        @unlink($this->root.'/secret.php');
        @rmdir($this->root);

        parent::tearDown();
    }

    public function test_index_html_and_stylesheet_resolve(): void
    {
        $this->assertSame($this->root.'/index.html', $this->website->resolve('index.html'));
        $this->assertSame($this->root.'/css/app.css', $this->website->resolve('css/app.css'));
    }

    public function test_directory_request_resolves_index_html(): void
    {
        mkdir($this->root.'/about', 0755, true);
        file_put_contents($this->root.'/about/index.html', '<html><body>About</body></html>');

        $this->assertSame($this->root.'/about/index.html', $this->website->resolve('about'));

        unlink($this->root.'/about/index.html');
        rmdir($this->root.'/about');
    }

    public function test_traversal_dotfiles_and_php_are_rejected(): void
    {
        file_put_contents($this->root.'/.env', 'APP_KEY=secret');
        file_put_contents($this->root.'/secret.php', '<?php');

        $this->assertNull($this->website->resolve('../.env'));
        $this->assertNull($this->website->resolve('.env'));
        $this->assertNull($this->website->resolve('secret.php'));
        $this->assertNull($this->website->resolve('missing.css'));
    }

    public function test_guest_injection_includes_login_and_signup(): void
    {
        $html = $this->website->inject(
            (string) file_get_contents($this->root.'/index.html'),
            user: null,
            registrationOpen: true,
        );

        $this->assertStringContainsString('data-state="guest"', $html);
        $this->assertStringContainsString('data-auth="login"', $html);
        $this->assertStringContainsString('Log in', $html);
        $this->assertStringContainsString('data-auth="register"', $html);
        $this->assertStringContainsString('Sign up', $html);
        $this->assertStringContainsString(route('login'), $html);
        $this->assertStringContainsString(route('register'), $html);
    }

    public function test_authenticated_injection_shows_dashboard_only(): void
    {
        $user = User::factory()->create();

        $html = $this->website->inject(
            (string) file_get_contents($this->root.'/index.html'),
            user: $user,
            registrationOpen: true,
        );

        $this->assertStringContainsString('data-state="user"', $html);
        $this->assertStringContainsString('data-auth="dashboard"', $html);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringNotContainsString('Log in', $html);
        $this->assertStringNotContainsString('Sign up', $html);
    }

    public function test_signup_is_omitted_when_registration_is_closed(): void
    {
        $html = $this->website->inject(
            (string) file_get_contents($this->root.'/index.html'),
            user: null,
            registrationOpen: false,
        );

        $this->assertStringContainsString('Log in', $html);
        $this->assertStringNotContainsString('Sign up', $html);
        $this->assertStringNotContainsString('data-auth="register"', $html);
    }

    public function test_missing_hook_inserts_a_fallback_after_body(): void
    {
        $html = $this->website->inject(
            '<html><body><h1>Plain site</h1></body></html>',
            user: null,
            registrationOpen: true,
        );

        $this->assertMatchesRegularExpression('/<body[^>]*>\s*<nav data-approvedesk-auth/i', $html);
        $this->assertStringContainsString('Log in', $html);
        $this->assertStringContainsString('Plain site', $html);
    }

    public function test_custom_code_is_injected_before_head_close(): void
    {
        Setting::put('brand.custom_code', '<script src="https://plausible.test/js/script.js"></script>');

        $html = $this->website->inject(
            '<html><head><title>Home</title></head><body><h1>Plain site</h1></body></html>',
            user: null,
            registrationOpen: true,
        );

        $this->assertStringContainsString('https://plausible.test/js/script.js', $html);
        $this->assertTrue(str_contains($html, '</script>') && strpos($html, '</script>') < strpos($html, '</head>'));
    }
}
