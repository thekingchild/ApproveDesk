<?php

namespace Tests\Feature\Themes;

use App\Actions\Themes\ArchiveThemeAction;
use App\Actions\Themes\SaveThemeAction;
use App\Actions\Themes\SetDefaultThemeAction;
use App\Actions\Themes\SetThemeEnabledAction;
use App\Actions\Themes\UpdateUserThemePreferenceAction;
use App\Models\SystemOwner;
use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\ThemeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ThemeDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ThemeSeeder::class);
    }

    public function test_built_in_catalogue_and_user_preference_relationship_exist(): void
    {
        $this->assertDatabaseHas('themes', ['key' => 'approvedesk', 'is_builtin' => true, 'is_enabled' => true, 'is_default' => true]);
        $user = User::factory()->create();
        $theme = Theme::query()->where('key', 'ocean')->firstOrFail();
        $user->update(['theme_id' => $theme->id]);
        $this->assertTrue($user->fresh()->theme->is($theme));
    }

    public function test_owner_can_create_only_fixed_valid_hex_tokens_and_builtins_are_immutable(): void
    {
        $owner = $this->owner();
        $custom = app(SaveThemeAction::class)->execute(null, [
            'name' => 'Forest', 'key' => 'forest',
            'primary_light' => '#166534', 'primary_dark' => '#4ADE80',
            'foreground_light' => '#FFFFFF', 'foreground_dark' => '#052E16',
        ], $owner);
        $this->assertFalse($custom->is_builtin);
        $this->assertStringContainsString('--ad-primary: #166534', app(ThemeService::class)->cssVariables($custom));
        $this->assertStringNotContainsString('<', app(ThemeService::class)->cssVariables($custom));

        $this->expectException(ValidationException::class);
        app(SaveThemeAction::class)->execute($custom, [
            'name' => 'Bad', 'key' => 'bad', 'primary_light' => 'red;}',
            'primary_dark' => '#000000', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#FFFFFF',
        ], $owner);
    }

    public function test_non_owner_cannot_manage_and_default_enabled_invariants_hold(): void
    {
        $owner = $this->owner();
        $roleOnly = User::factory()->create()->assignRole('super-admin');
        $default = Theme::query()->where('is_default', true)->firstOrFail();
        $other = Theme::query()->where('id', '!=', $default->id)->firstOrFail();

        try {
            app(SetThemeEnabledAction::class)->execute($default, false, $owner);
            $this->fail('Expected default guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('theme', $exception->errors());
        }
        app(SetDefaultThemeAction::class)->execute($other, $owner);
        $this->assertTrue($other->fresh()->is_default);
        $this->assertTrue($other->fresh()->is_enabled);

        $this->expectException(AuthorizationException::class);
        app(SetThemeEnabledAction::class)->execute($default, false, $roleOnly);
    }

    public function test_preference_requires_enabled_theme_and_falls_back_to_default(): void
    {
        $owner = $this->owner();
        $user = User::factory()->create();
        $selected = Theme::query()->where('is_default', false)->where('is_enabled', true)->firstOrFail();
        app(UpdateUserThemePreferenceAction::class)->execute($user, $selected->id);
        $this->assertTrue(app(ThemeService::class)->effectiveFor($user)->is($selected));

        app(SetThemeEnabledAction::class)->execute($selected, false, $owner);
        $this->assertTrue(app(ThemeService::class)->effectiveFor($user)->is(Theme::query()->where('is_default', true)->firstOrFail()));
        $this->assertSame($selected->id, $user->fresh()->theme_id);
    }

    public function test_theme_seeder_preserves_owner_availability_and_default_choices(): void
    {
        $owner = $this->owner();
        $approvedesk = Theme::query()->where('key', 'approvedesk')->firstOrFail();
        $ocean = Theme::query()->where('key', 'ocean')->firstOrFail();

        app(SetDefaultThemeAction::class)->execute($ocean, $owner);
        app(SetThemeEnabledAction::class)->execute($approvedesk->fresh(), false, $owner);
        $this->seed(ThemeSeeder::class);

        $this->assertFalse($approvedesk->fresh()->is_enabled);
        $this->assertFalse($approvedesk->fresh()->is_default);
        $this->assertTrue($ocean->fresh()->is_default);
        $this->assertSame(1, Theme::query()->where('is_default', true)->count());
    }

    public function test_only_custom_non_default_themes_can_be_archived(): void
    {
        $owner = $this->owner();
        $builtIn = Theme::query()->where('is_builtin', true)->where('is_default', false)->firstOrFail();

        try {
            app(ArchiveThemeAction::class)->execute($builtIn, $owner);
            $this->fail('Expected built-in archive guard.');
        } catch (ValidationException $exception) {
            $this->assertSame('Built-in themes cannot be archived.', $exception->errors()['theme'][0]);
        }

        $custom = Theme::factory()->create();
        app(ArchiveThemeAction::class)->execute($custom, $owner);

        $this->assertNotNull($custom->fresh()->archived_at);
        $this->assertFalse($custom->fresh()->is_enabled);
    }

    public function test_custom_theme_requires_wcag_contrast_for_light_and_dark_pairs(): void
    {
        $owner = $this->owner();

        foreach ([
            [
                'key' => 'low-light',
                'primary_light' => '#777777',
                'foreground_light' => '#888888',
                'primary_dark' => '#000000',
                'foreground_dark' => '#FFFFFF',
                'field' => 'foreground_light',
                'message' => 'The light primary and foreground colors must have a contrast ratio of at least 4.5:1.',
            ],
            [
                'key' => 'low-dark',
                'primary_light' => '#000000',
                'foreground_light' => '#FFFFFF',
                'primary_dark' => '#777777',
                'foreground_dark' => '#888888',
                'field' => 'foreground_dark',
                'message' => 'The dark primary and foreground colors must have a contrast ratio of at least 4.5:1.',
            ],
        ] as $case) {
            try {
                app(SaveThemeAction::class)->execute(null, [
                    'name' => 'Low contrast',
                    'key' => $case['key'],
                    'primary_light' => $case['primary_light'],
                    'foreground_light' => $case['foreground_light'],
                    'primary_dark' => $case['primary_dark'],
                    'foreground_dark' => $case['foreground_dark'],
                ], $owner);
                $this->fail('Expected contrast validation.');
            } catch (ValidationException $exception) {
                $this->assertSame($case['message'], $exception->errors()[$case['field']][0]);
            }
        }

        $this->assertDatabaseMissing('themes', ['key' => 'low-light']);
        $this->assertDatabaseMissing('themes', ['key' => 'low-dark']);
    }

    private function owner(): User
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        return $owner;
    }
}
