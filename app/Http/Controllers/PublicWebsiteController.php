<?php

namespace App\Http\Controllers;

use App\Support\PublicWebsite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicWebsiteController extends Controller
{
    public function home(Request $request, PublicWebsite $website): Response|BinaryFileResponse
    {
        return $website->response('index.html', $request->user());
    }

    public function asset(Request $request, PublicWebsite $website, string $path): Response|BinaryFileResponse
    {
        return $website->response($path, $request->user());
    }
}
