<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    public function store(PushSubscriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        PushSubscription::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'endpoint' => $validated['endpoint'],
            ],
            [
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aes128gcm',
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(PushSubscriptionRequest $request): JsonResponse
    {
        $endpoint = $request->validated('endpoint');
        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $endpoint)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
