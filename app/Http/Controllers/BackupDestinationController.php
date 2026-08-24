<?php

namespace App\Http\Controllers;

use App\Actions\Backups\StoreBackupDestinationAction;
use App\Models\BackupDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BackupDestinationController extends Controller
{
    public function store(Request $request, StoreBackupDestinationAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'in:s3,gdrive'],
            'enabled' => ['sometimes', 'boolean'],
            'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:80'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'path_style' => ['nullable', 'string', 'max:1'],
            'prefix' => ['nullable', 'string', 'max:120'],
            'folder_id' => ['nullable', 'string', 'max:255'],
            'refresh_token' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $action->execute($validated + ['enabled' => $request->boolean('enabled', true)]);

        return redirect()->route('admin.backups.index')->with('status', __('Destination saved.'));
    }

    public function update(Request $request, BackupDestination $destination, StoreBackupDestinationAction $action): RedirectResponse
    {
        $action->update($destination, $request->all() + ['enabled' => $request->boolean('enabled')]);

        return redirect()->route('admin.backups.index')->with('status', $request->boolean('clear')
            ? __('Destination credentials cleared.')
            : __('Destination saved.'));
    }

    public function test(BackupDestination $destination, StoreBackupDestinationAction $action): RedirectResponse
    {
        $ok = $action->test($destination);

        return redirect()->route('admin.backups.index')->with(
            'status',
            $ok ? __('Destination answered.') : __('Could not reach that destination.'),
        );
    }
}
