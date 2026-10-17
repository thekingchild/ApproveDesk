<?php

use App\Enums\BackupDestinationKind;
use App\Models\BackupDestination;
use App\Services\Backups\BackupRecorder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Backup destinations on the Backups page (ADR 0006 split). Saving and testing
 * a destination are plain POST forms handled by BackupDestinationController.
 */
new class extends Component {
    public function mount(): void
    {
        $this->authorize('backups.view');
    }

    /**
     * Saved destinations. Before the first backup creates the local row, the
     * local disk is listed unsaved: viewing the page never writes (RV-ARC-09).
     *
     * @return Collection<int, BackupDestination>
     */
    #[Computed]
    public function destinations(): Collection
    {
        $destinations = BackupDestination::query()->orderBy('id')->get()->toBase();

        if ($destinations->doesntContain(fn (BackupDestination $destination): bool => $destination->kind === BackupDestinationKind::Local)) {
            $destinations->prepend(app(BackupRecorder::class)->unsavedLocalDestination());
        }

        return $destinations;
    }

    /** A backup run records each remote's last error. */
    #[On('backups-changed')]
    public function refreshDestinations(): void
    {
        unset($this->destinations);
    }
} ?>

<div>
    <div class="mt-8">
        <flux:heading size="sm">{{ __('Destinations') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The local disk is always used. S3-compatible storage and Google Drive are optional and configured separately. A remote that is missing its secret is skipped and does not fail the local copy.') }}</flux:text>
        <ul class="mt-4 space-y-2 text-sm">
            @foreach ($this->destinations as $destination)
                <li class="flex flex-wrap items-center gap-3" wire:key="backup-destination-{{ $destination->id ?? 'local' }}" data-test="backup-destination">
                    <span>
                        <span class="font-medium">{{ $destination->name }}</span>
                        — {{ $destination->kind->label() }}
                        ({{ $destination->enabled ? __('on') : __('off') }})
                        @if ($destination->last_error)
                            <span class="text-red-600">{{ $destination->last_error }}</span>
                        @endif
                    </span>
                    @if ($destination->kind !== BackupDestinationKind::Local)
                        <form method="POST" action="{{ route('admin.backups.destinations.test', $destination) }}">
                            @csrf
                            <flux:button type="submit" size="xs">{{ __('Test') }}</flux:button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    @can('backups.destinations.manage')
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <flux:card data-test="backup-s3-card">
                <flux:heading size="sm">{{ __('S3-compatible') }}</flux:heading>
                <flux:text class="mt-1">{{ __('R2, Wasabi, MinIO, Spaces, or any S3-compatible bucket. Used only for snapshots, not for request integrations.') }}</flux:text>
                <form method="POST" action="{{ route('admin.backups.destinations.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="kind" value="s3">
                    <input type="hidden" name="enabled" value="1">
                    <flux:input name="name" :label="__('Name')" :value="old('name', __('Object storage'))" required />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input name="key" :label="__('Access key')" autocomplete="off" />
                        <flux:input name="secret" type="password" :label="__('Secret')" autocomplete="off" />
                        <flux:input name="region" :label="__('Region')" />
                        <flux:input name="bucket" :label="__('Bucket')" />
                        <flux:input name="endpoint" :label="__('Endpoint')" />
                        <flux:input name="prefix" :label="__('Prefix')" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="path_style" value="1">
                        {{ __('Path-style endpoint') }}
                    </label>
                    <flux:button type="submit" variant="primary" data-test="save-backup-destination">{{ __('Save S3 destination') }}</flux:button>
                </form>
            </flux:card>

            <flux:card data-test="backup-gdrive-card">
                <flux:heading size="sm">{{ __('Google Drive') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Unattended copies into a Drive folder. This is not the request integration connector. Save a refresh token so the schedule can run without a browser.') }}</flux:text>
                <form method="POST" action="{{ route('admin.backups.destinations.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <input type="hidden" name="kind" value="gdrive">
                    <input type="hidden" name="enabled" value="1">
                    <flux:input name="name" :label="__('Name')" :value="__('Google Drive')" required />
                    <flux:input name="folder_id" :label="__('Folder id')" />
                    <flux:input name="client_id" :label="__('Client id')" autocomplete="off" />
                    <flux:input name="client_secret" type="password" :label="__('Client secret')" autocomplete="off" />
                    <flux:input name="refresh_token" type="password" :label="__('Refresh token')" autocomplete="off" />
                    <flux:button type="submit" variant="primary" data-test="save-backup-gdrive">{{ __('Save Drive destination') }}</flux:button>
                </form>
            </flux:card>
        </div>
    @endcan
</div>
