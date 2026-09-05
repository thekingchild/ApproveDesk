<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateSystemSettingsAction;
use App\Http\Requests\UpdateSystemSettingsRequest;
use Illuminate\Http\RedirectResponse;

class SystemSettingsController extends Controller
{
    public function __invoke(UpdateSystemSettingsRequest $request, UpdateSystemSettingsAction $update): RedirectResponse
    {
        $update->execute($request->validated(), $request->user());

        return redirect()->route('settings.system')->with('status', __('System settings saved. Restart long-lived queue workers so they load the new configuration.'));
    }
}
