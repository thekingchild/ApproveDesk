<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RequestTypeSchemaResource;
use App\Models\RequestType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RequestTypeSchemaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        abort_unless(request()->user()?->tokenCan('request-types:read') || request()->user()?->tokenCan('submissions:read'), 403);

        $types = RequestType::query()
            ->where('is_active', true)
            ->with(['publishedVersion.fields'])
            ->orderBy('name')
            ->get()
            ->filter(fn (RequestType $type): bool => $type->publishedVersion !== null)
            ->values();

        return RequestTypeSchemaResource::collection($types);
    }

    public function show(string $slug): RequestTypeSchemaResource
    {
        abort_unless(request()->user()?->tokenCan('request-types:read') || request()->user()?->tokenCan('submissions:read'), 403);

        $type = RequestType::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['publishedVersion.fields'])
            ->firstOrFail();

        abort_unless($type->publishedVersion !== null, 404);

        return new RequestTypeSchemaResource($type);
    }
}
