<?php

use App\Actions\WorkflowTemplates\RestoreWorkflowTemplateVersionAction;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateVersion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Versions card of the workflow builder (ADR 0006 split): restore an earlier
 * version as the draft and compare two versions. A restore announces
 * workflow-version-restored so the builder reloads the draft; the builder
 * announces workflow-versions-changed after it saves or publishes.
 */
new class extends Component {
    #[Locked]
    public int $templateId;

    public ?int $compareFrom = null;

    public ?int $compareTo = null;

    public function mount(int $templateId): void
    {
        $this->templateId = $templateId;
        $this->authorize('update', $this->template());
    }

    /** @return Collection<int, WorkflowTemplateVersion> */
    #[Computed]
    public function versions(): Collection
    {
        return WorkflowTemplateVersion::query()
            ->where('workflow_template_id', $this->templateId)
            ->with('steps')
            ->orderByDesc('version')
            ->get();
    }

    public function restoreVersion(int $versionId): void
    {
        $this->authorize('update', $this->template());
        $version = WorkflowTemplateVersion::query()
            ->where('workflow_template_id', $this->templateId)
            ->findOrFail($versionId);

        app(RestoreWorkflowTemplateVersionAction::class)->execute($version, Auth::user());

        unset($this->versions);
        $this->dispatch('workflow-version-restored');
        Flux::toast('Version restored as a draft.', variant: 'success');
    }

    #[On('workflow-versions-changed')]
    public function refreshVersions(): void
    {
        unset($this->versions);
    }

    private function template(): WorkflowTemplate
    {
        return WorkflowTemplate::query()->findOrFail($this->templateId);
    }
}; ?>

<flux:card>
    <flux:heading size="sm">{{ __('Versions') }}</flux:heading>
    <ul class="mt-3 space-y-2 text-sm">
        @foreach ($this->versions as $version)
            <li class="flex items-center justify-between gap-2" wire:key="version-{{ $version->id }}">
                <span>v{{ $version->version }} · {{ $version->status->value }}</span>
                <flux:button size="xs" wire:click="restoreVersion({{ $version->id }})" data-test="restore-version-{{ $version->id }}">{{ __('Restore draft') }}</flux:button>
            </li>
        @endforeach
    </ul>
    <div class="mt-3 grid grid-cols-2 gap-2">
        <flux:select wire:model.live="compareFrom" :label="__('Compare from')">
            <flux:select.option value="">{{ __('Version') }}</flux:select.option>
            @foreach ($this->versions as $version)
                <flux:select.option :value="$version->id">v{{ $version->version }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="compareTo" :label="__('Compare to')">
            <flux:select.option value="">{{ __('Version') }}</flux:select.option>
            @foreach ($this->versions as $version)
                <flux:select.option :value="$version->id">v{{ $version->version }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
    @if ($compareFrom && $compareTo)
        @php
            $from = $this->versions->firstWhere('id', (int) $compareFrom);
            $to = $this->versions->firstWhere('id', (int) $compareTo);
        @endphp
        @if ($from && $to)
            <dl class="mt-3 space-y-1 text-sm" data-test="version-compare">
                <div>{{ __('From hash') }}: {{ str($from->schema_hash)->limit(12) }}</div>
                <div>{{ __('To hash') }}: {{ str($to->schema_hash)->limit(12) }}</div>
                <div>{{ __('From steps') }}: {{ $from->steps->pluck('name')->implode(', ') }}</div>
                <div>{{ __('To steps') }}: {{ $to->steps->pluck('name')->implode(', ') }}</div>
            </dl>
        @endif
    @endif
</flux:card>
