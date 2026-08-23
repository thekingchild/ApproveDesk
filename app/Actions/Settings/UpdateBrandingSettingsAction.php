<?php

namespace App\Actions\Settings;

use App\DTOs\BrandingSettingsData;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class UpdateBrandingSettingsAction
{
    public function execute(BrandingSettingsData $data): void
    {
        Setting::put('brand.tagline', $data->tagline);
        Setting::put('brand.accent_color', $data->accentColor);
        Setting::put('brand.login_background_overlay', (string) $data->overlay);
        Setting::put('brand.custom_css', $data->customCss === '' ? null : $data->customCss);
        Setting::put('auth.allow_registration', $data->allowRegistration ? 'true' : 'false');
        Setting::put('auth.registration_mode', in_array($data->registrationMode, ['immediate', 'otp'], true) ? $data->registrationMode : 'otp');

        // Normalise the allow-list: lowercase, one per line, no blanks/dupes.
        $domains = collect(preg_split('/[\r\n,]+/', $data->allowedRegistrationDomains) ?: [])
            ->map(fn (string $domain): string => mb_strtolower(trim($domain)))
            ->filter()
            ->unique()
            ->implode("\n");
        Setting::put('auth.allowed_registration_domains', $domains === '' ? null : $domains);

        Setting::put('brand.meta_title', $data->metaTitle === '' ? null : $data->metaTitle);
        Setting::put('brand.meta_description', $data->metaDescription === '' ? null : $data->metaDescription);

        $this->replaceAsset('brand.login_background', $data->background, $data->removeBackground);
        $this->replaceAsset('brand.logo', $data->logo, $data->removeLogo);
        $this->replaceAsset('brand.favicon', $data->favicon, $data->removeFavicon);
    }

    private function replaceAsset(string $key, ?UploadedFile $upload, bool $remove): void
    {
        $current = Setting::get($key);

        if ($remove && $current) {
            Storage::disk('public')->delete($current);
            Setting::put($key, null);
            $current = null;
        }

        if ($upload === null) {
            return;
        }

        if ($current) {
            Storage::disk('public')->delete($current);
        }

        $stored = $upload->store('branding', 'public');

        if (! is_string($stored) || $stored === '') {
            throw new RuntimeException('Could not store the branding file.');
        }

        Setting::put($key, $stored);
    }
}
