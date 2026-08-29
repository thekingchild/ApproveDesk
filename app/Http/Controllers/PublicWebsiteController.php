<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use App\Support\PublicWebsite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicWebsiteController extends Controller
{
    public function home(Request $request, PublicWebsite $website): Response|BinaryFileResponse|RedirectResponse
    {
        if (! Branding::landingPageEnabled()) {
            return redirect()->route($request->user() === null ? 'login' : 'dashboard');
        }

        return $website->response('index.html', $request->user());
    }

    public function asset(Request $request, PublicWebsite $website, string $path): Response|BinaryFileResponse
    {
        abort_unless(Branding::landingPageEnabled(), 404);

        return $website->response($path, $request->user());
    }
}
