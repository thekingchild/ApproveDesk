<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreProfileImageAction
{
    public function execute(User $user, UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['photo' => __('The photo must be a valid image no larger than 2 MB.')]);
        }
        $bytes = file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring((string) $bytes);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = is_array($info) ? $info['mime'] : '';
        if (! is_array($info) || ! in_array($mime, $allowed, true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 20_000_000 || ! $this->hasExactContainerLength((string) $bytes, $mime)) {
            throw ValidationException::withMessages(['photo' => __('Use a valid JPEG, PNG, or WebP image with safe dimensions.')]);
        }
        $source = @imagecreatefromstring((string) $bytes);
        if ($source === false) {
            throw ValidationException::withMessages(['photo' => __('The image could not be decoded safely.')]);
        }

        $side = min(imagesx($source), imagesy($source));
        $x = (int) ((imagesx($source) - $side) / 2);
        $y = (int) ((imagesy($source) - $side) / 2);
        $targetSize = min(512, $side);
        $target = imagecreatetruecolor($targetSize, $targetSize);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, $x, $y, $targetSize, $targetSize, $side, $side);
        ob_start();
        imagepng($target, null, 9);
        $clean = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        $path = 'profile-images/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $clean);
        $old = $user->profile_image_path;
        $user->forceFill(['profile_image_path' => $path, 'profile_image_mime' => 'image/png'])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        activity()->causedBy($user)->performedOn($user)->event('profile-image-updated')->log('Profile image updated');
    }

    public function remove(User $user): void
    {
        $old = $user->profile_image_path;
        $user->forceFill(['profile_image_path' => null, 'profile_image_mime' => null])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        activity()->causedBy($user)->performedOn($user)->event('profile-image-removed')->log('Profile image removed');
    }

    private function hasExactContainerLength(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/png' => str_ends_with($bytes, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82"),
            'image/jpeg' => str_ends_with($bytes, "\xFF\xD9"),
            'image/webp' => $this->webpHasExactRiffLength($bytes),
            default => false,
        };
    }

    private function webpHasExactRiffLength(string $bytes): bool
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return false;
        }

        $length = unpack('V', substr($bytes, 4, 4));

        return is_array($length) && isset($length[1]) && strlen($bytes) === $length[1] + 8;
    }
}
