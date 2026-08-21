<?php

namespace App\Http\Controllers;

use App\Actions\Settings\StoreNotificationChannelCredentialsAction;
use App\Http\Requests\StoreNotificationChannelCredentialsRequest;
use Illuminate\Http\RedirectResponse;

class NotificationChannelCredentialController extends Controller
{
    public function __invoke(
        StoreNotificationChannelCredentialsRequest $request,
        StoreNotificationChannelCredentialsAction $store,
    ): RedirectResponse {
        $validated = $request->validated();
        $channel = $request->channel();
        unset($validated['channel'], $validated['clear']);

        /** @var array<string, string|null> $credentials */
        $credentials = [];
        foreach ($validated as $field => $value) {
            $credentials[$field] = is_string($value) ? $value : null;
        }

        $store->execute($channel, $credentials, $request->boolean('clear'));

        return redirect()
            ->route('settings.notification-channels')
            ->with('status', $request->boolean('clear')
                ? $channel->label().' credentials cleared.'
                : $channel->label().' credentials saved.');
    }
}
