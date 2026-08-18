<?php

namespace Tests\Browser;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

class AccessibilityResponsiveTest extends DuskTestCase
{
    use ChecksAccessibility, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_core_pages_have_no_critical_or_serious_accessibility_violations(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create(['title' => 'Accessible request detail']);
        app(WorkflowEngine::class)->initialize($submission);

        $this->browse(function (Browser $browser) use ($submitter, $approver, $submission): void {
            $browser->logout()->visitRoute('login')->waitForText('Log in');
            $this->assertNoSeriousAccessibilityViolations($browser, 'Login');

            $browser->loginAs($submitter)->visitRoute('dashboard')->waitForText('Welcome back');
            $this->assertNoSeriousAccessibilityViolations($browser, 'Dashboard');

            $browser->visitRoute('submissions.create')->waitFor('@request-title');
            $this->assertNoSeriousAccessibilityViolations($browser, 'Create request');

            $browser->loginAs($approver)->visitRoute('approvals.inbox')->waitForText('Approval workbench');
            $this->assertNoSeriousAccessibilityViolations($browser, 'Approval inbox');

            $browser->visitRoute('submissions.show', $submission)->waitForText('Accessible request detail');
            $this->assertNoSeriousAccessibilityViolations($browser, 'Submission detail');
        });
    }

    public function test_core_navigation_is_responsive_at_required_widths(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->browse(function (Browser $browser) use ($submitter): void {
            $browser->loginAs($submitter);

            foreach ([360, 390, 768, 1280, 1440] as $width) {
                $browser->resize($width, 900)
                    ->visitRoute('dashboard')
                    ->waitForText('Welcome back');

                [$scrollWidth, $viewportWidth] = $browser->script('return [document.documentElement.scrollWidth, window.innerWidth]')[0];
                $this->assertLessThanOrEqual($viewportWidth, $scrollWidth, "Dashboard overflows horizontally at {$width}px.");

                $mobileNavigationVisible = $browser->script(<<<'JS'
                    const toggle = document.querySelector('[dusk="mobile-navigation"]');

                    return Boolean(toggle && toggle.getClientRects().length);
                JS)[0];

                $this->assertSame(
                    $width < 1024,
                    $mobileNavigationVisible,
                    "Mobile navigation visibility is incorrect at {$width}px.",
                );
            }
        });
    }
}
