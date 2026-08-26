<?php

namespace Tests\Feature\Settings;

use App\Actions\Settings\UpdateEmailApprovalSecurityAction;
use App\Models\Setting;
use App\Models\User;
use App\Support\SecondFactorAssurance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalLinkSecuritySettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_setting_is_off_by_default_and_visible_to_an_administrator(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->assertSet('emailApprovalSecondFactorRequired', false)
            ->assertSee('Require sign-in and a second factor for approval links');
    }

    public function test_unassured_administrator_cannot_enable_the_setting(): void
    {
        $admin = User::factory()->withTwoFactor()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('emailApprovalSecondFactorRequired', true)
            ->call('save')
            ->assertHasErrors('emailApprovalSecondFactorRequired');

        $this->assertSame('0', Setting::get('approvals.email_require_second_factor', '0'));
    }

    public function test_assured_administrator_can_enable_the_setting(): void
    {
        $admin = User::factory()->withTwoFactor()->create()->assignRole('admin');
        $request = Request::create('/settings/approvals');
        $session = new Store('testing', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        app(SecondFactorAssurance::class)->mark($request, $admin, 'totp');

        app(UpdateEmailApprovalSecurityAction::class)->execute(true, $admin, $request);

        $this->assertSame('1', Setting::get('approvals.email_require_second_factor'));
    }

    public function test_administrator_can_disable_the_setting_without_current_assurance(): void
    {
        Setting::put('approvals.email_require_second_factor', '1');
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('emailApprovalSecondFactorRequired', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0', Setting::get('approvals.email_require_second_factor'));
    }

    public function test_existing_enabled_value_loads_without_requiring_assurance_to_save_other_settings(): void
    {
        Setting::put('approvals.email_require_second_factor', '1');
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->assertSet('emailApprovalSecondFactorRequired', true)
            ->set('emailLinkExpirationHours', 24)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', Setting::get('approvals.email_require_second_factor'));
        $this->assertSame('24', Setting::get('approvals.email_link_expiration_hours'));
    }
}
