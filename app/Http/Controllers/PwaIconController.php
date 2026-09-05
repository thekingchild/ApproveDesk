<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class PwaIconController
{
    public function __invoke(int $size): Response
    {
        abort_unless(in_array($size, [32, 180, 192, 512], true), 404);
        $source = null;
        $path = Setting::get('brand.logo');
        if ($path && Storage::disk('local')->exists($path)) {
            $bytes = Storage::disk('local')->get($path);
            $source = @imagecreatefromstring($bytes);
        }

        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        if ($source !== false && $source !== null) {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min($size / $width, $size / $height) * .82;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            imagecopyresampled($image, $source, (int) (($size - $targetWidth) / 2), (int) (($size - $targetHeight) / 2), 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($source);
        } else {
            imagealphablending($image, true);
            $blue = imagecolorallocate($image, 79, 70, 229);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledellipse($image, $size / 2, $size / 2, $size * .88, $size * .88, $blue);
            imagesetthickness($image, max(2, (int) ($size / 18)));
            imageline($image, $size * .27, $size * .52, $size * .44, $size * .68, $white);
            imageline($image, $size * .44, $size * .68, $size * .74, $size * .34, $white);
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
