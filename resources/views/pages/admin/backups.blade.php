<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Backups page shell (ADR 0006 split, RV-ARC-09): each section is a child
 * component under livewire/admin/backups that authorizes its own actions.
 */
new #[Title('Backups')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('backups.view');
    }
}; ?>

<div>
    <x-ui.page-header :title="__('Backups')" :description="__('Separate database and file snapshots. Host backups stay recommended.')" help-article="backups" />

    @if (session('status'))
        <flux:text class="mt-4">{{ session('status') }}</flux:text>
    @endif

    <livewire:admin.backups.schedule />
    <livewire:admin.backups.snapshots />
    <livewire:admin.backups.destinations />
    <livewire:admin.backups.restore-history />
</div>
