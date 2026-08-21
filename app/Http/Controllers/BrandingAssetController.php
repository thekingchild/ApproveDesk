<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves public branding files (login background, logo, favicon) from the
 * public disk without requiring `storage:link`. Paths are constrained to
 * the branding/ prefix; this is identity artwork, not user attachments.
 */
class BrandingAssetController extends Controller
{
    private const MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
    ];

    public function __invoke(string $file): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $file) === 1, 404);

        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        abort_unless(isset(self::MIMES[$extension]), 404);

        $path = 'branding/'.$file;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response(
            $path,
            $file,
            [
                'Content-Type' => self::MIMES[$extension],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, max-age=86400',
            ],
            'inline',
        );
    }
}
