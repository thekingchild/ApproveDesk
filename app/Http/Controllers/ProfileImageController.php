<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class ProfileImageController extends Controller
{
    public function __invoke(User $user): Response
    {
        Gate::authorize('viewProfileImage', $user);
        abort_unless($user->profile_image_path && Storage::disk('local')->exists($user->profile_image_path), 404);

        return response(Storage::disk('local')->get($user->profile_image_path), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
