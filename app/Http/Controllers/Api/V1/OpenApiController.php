<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\Yaml\Yaml;

class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $path = base_path('docs/openapi-v1.yaml');
        $spec = Yaml::parseFile($path);

        return response()->json($spec);
    }
}
