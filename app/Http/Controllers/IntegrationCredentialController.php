<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnector;
use App\Support\OutboundDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class IntegrationCredentialController extends Controller
{
    public function __invoke(Request $request, IntegrationConnector $connector): RedirectResponse
    {
        Gate::authorize('update', $connector);

        if ($request->boolean('clear')) {
            $connector->forceFill(['credentials' => null, 'last_error' => null])->save();

            return redirect()->route('admin.integrations.index')->with('status', __('Credentials cleared.'));
        }

        $fields = $connector->type->credentialFields();
        $current = $connector->credentialMap();

        foreach ($fields as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value === '') {
                continue;
            }

            if ($this->isDestinationUrlField($field)) {
                try {
                    app(OutboundDestination::class)->validate($value);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$field => $exception->getMessage()]);
                }
            }

            $current[$field] = $value;
        }

        $connector->forceFill(['credentials' => $current])->save();

        return redirect()->route('admin.integrations.index')->with('status', __('Credentials saved.'));
    }

    private function isDestinationUrlField(string $field): bool
    {
        return in_array($field, ['url', 'webhook_url', 'site_url'], true)
            || str_ends_with($field, '_url');
    }
}
