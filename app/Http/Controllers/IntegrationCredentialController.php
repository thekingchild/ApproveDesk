<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
            if ($value !== '') {
                $current[$field] = $value;
            }
        }

        $connector->forceFill(['credentials' => $current])->save();

        return redirect()->route('admin.integrations.index')->with('status', __('Credentials saved.'));
    }
}
