<?php

namespace App\Support;

use App\Models\Setting;

final class SecuritySettings
{
    public static function watermarkDownloads(): bool
    {
        return Setting::get('privacy.watermark_downloads', '0') === '1';
    }
}
