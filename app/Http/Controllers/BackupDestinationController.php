<?php

namespace App\Http\Controllers;

use App\Actions\Backups\StoreBackupDestinationAction;
use App\Http\Requests\StoreBackupDestinationRequest;
use App\Http\Requests\UpdateBackupDestinationRequest;
use App\Models\BackupDestination;
use Illuminate\Http\RedirectResponse;

class BackupDestinationController extends Controller
{
    public function store(StoreBackupDestinationRequest $request, StoreBackupDestinationAction $action): RedirectResponse
    {
        $action->execute($request->validated() + ['enabled' => $request->boolean('enabled', true)]);

        return redirect()->route('admin.backups.index')->with('status', __('Destination saved.'));
    }

    public function update(UpdateBackupDestinationRequest $request, BackupDestination $destination, StoreBackupDestinationAction $action): RedirectResponse
    {
        $action->update($destination, $request->validated() + ['enabled' => $request->boolean('enabled')]);

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
