<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\UpdateIntegrationCredentialsAction;
use App\Http\Requests\UpdateIntegrationCredentialsRequest;
use App\Models\IntegrationConnector;
use Illuminate\Http\RedirectResponse;

class IntegrationCredentialController extends Controller
{
    public function __invoke(UpdateIntegrationCredentialsRequest $request, IntegrationConnector $connector, UpdateIntegrationCredentialsAction $credentials): RedirectResponse
    {
        if ($request->boolean('clear')) {
            $credentials->clear($connector, $request->user());

            return redirect()->route('admin.integrations.index')->with('status', __('Credentials cleared.'));
        }

        $credentials->execute($connector, $request->validated(), $request->user());

        return redirect()->route('admin.integrations.index')->with('status', __('Credentials saved.'));
    }
}
