<?php

use App\Livewire\Forms\WorkflowStepsForm;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowTemplates\WorkflowGraphSimulator;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/*
 * Simulation card of the workflow builder (ADR 0006 split). It traces the
 * steps as they are in the editor, without saving a draft first (RV-ARC-07).
 */
new class extends Component {
    #[Locked]
    public int $templateId;

    /** @var array<int, array<string, mixed>> */
    #[Reactive]
    public array $steps = [];

    public string $sampleTitle = 'Sample expense request';

    public string $samplePriority = 'high';

    public string $sampleMetadata = '{"amount": 15000}';

    /** @var list<array{order: int, name: string, kind: string, detail: string}> */
    public array $simulationTrace = [];

    public function mount(int $templateId): void
    {
        $this->templateId = $templateId;
        $this->authorize('update', WorkflowTemplate::query()->findOrFail($templateId));
    }

    public function simulate(): void
    {
        $this->authorize('update', WorkflowTemplate::query()->findOrFail($this->templateId));
        Validator::make(['steps' => $this->steps], WorkflowStepsForm::rules())->validate();

        $metadata = json_decode($this->sampleMetadata, true);
        $this->simulationTrace = app(WorkflowGraphSimulator::class)->traceDraft(
            WorkflowStepsForm::toData($this->steps),
            [
                'title' => $this->sampleTitle,
                'priority' => $this->samplePriority,
                'metadata' => is_array($metadata) ? $metadata : [],
            ],
        );
    }
}; ?>

<div class="space-y-4">
    <flux:card>
        <flux:heading size="sm">{{ __('Simulation') }}</flux:heading>
        <div class="mt-3 space-y-3">
            <flux:input wire:model="sampleTitle" :label="__('Sample title')" />
            <flux:select wire:model="samplePriority" :label="__('Priority')">
                <flux:select.option value="low">{{ __('Low') }}</flux:select.option>
                <flux:select.option value="normal">{{ __('Normal') }}</flux:select.option>
                <flux:select.option value="high">{{ __('High') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="sampleMetadata" :label="__('Metadata JSON')" />
            @if ($errors->any())
                <flux:text class="text-sm text-red-600 dark:text-red-400" data-test="simulation-errors">{{ $errors->first() }}</flux:text>
            @endif
            <flux:button size="sm" wire:click="simulate" data-test="run-simulation">{{ __('Run simulation') }}</flux:button>
        </div>
    </flux:card>

    @if ($simulationTrace !== [])
        <flux:card data-test="simulation-trace">
            <flux:heading size="sm">{{ __('Simulation trace') }}</flux:heading>
            <ol class="mt-3 space-y-2">
                @foreach ($simulationTrace as $entry)
                    <li><span class="font-semibold">{{ $entry['name'] }}</span> — {{ $entry['detail'] }}</li>
                @endforeach
            </ol>
        </flux:card>
    @endif
</div>
