<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Branding and behavior defaults. All overridable from the admin UI.
     */
    private const DEFAULTS = [
        'brand.tagline' => 'Approvals, clearly tracked.',
        'brand.accent_color' => '#4f46e5',
        'brand.login_background' => null,
        'brand.login_background_overlay' => 40,
        'brand.custom_css' => null,
        'brand.custom_code' => null,
        'brand.logo' => null,
        'brand.favicon' => null,
        'brand.meta_title' => null,
        'brand.meta_description' => null,
        'auth.allow_registration' => 'true',
        'approvals.email_link_expiration_hours' => '72',
        'notifications.teams.enabled' => 'false',
        'notifications.slack.enabled' => 'false',
        'notifications.whatsapp.enabled' => 'false',
        'notifications.telegram.enabled' => 'false',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
