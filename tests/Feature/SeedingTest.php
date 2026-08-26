<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SystemOwner;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_permissions_seed_idempotently(): void
    {
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $this->assertSame(
            ['admin', 'approver', 'auditor', 'department-head', 'submitter', 'super-admin', 'viewer'],
            Role::orderBy('name')->pluck('name')->values()->all(),
        );

        $submitter = User::factory()->create()->assignRole('submitter');

        $this->assertTrue($submitter->can('submissions.create'));
        $this->assertFalse($submitter->can('templates.manage'));

        $admin = User::factory()->create()->assignRole('admin');
        $this->assertTrue($admin->can('templates.manage'));

        $superAdmin = User::factory()->create()->assignRole('super-admin');
        $this->assertFalse($superAdmin->can('anything.at.all'), 'role-only super-admin does not bypass gates');

        SystemOwner::query()->create([
            'id' => SystemOwner::SINGLETON_ID,
            'user_id' => $superAdmin->id,
        ]);
        $this->assertTrue($superAdmin->fresh()->can('anything.at.all'), 'system owner bypasses all gates');
    }

    public function test_settings_seeder_creates_defaults_without_overriding(): void
    {
        Artisan::call('db:seed', ['--class' => SettingsSeeder::class]);

        $this->assertSame('#4f46e5', Setting::get('brand.accent_color'));
    }

    public function test_demo_seeder_builds_default_template(): void
    {
        $this->artisan('db:seed');

        $template = WorkflowTemplate::query()->where('is_default', true)->first();

        $this->assertNotNull($template);
        $this->assertCount(2, $template->steps);
        $this->assertTrue($template->steps->every(fn ($step) => $step->assignee_type->value === 'user'));
    }
}
