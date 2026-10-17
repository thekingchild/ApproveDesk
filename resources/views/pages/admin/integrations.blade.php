<?php

use App\Actions\Integrations\ReplayIntegrationDeliveryAction;
use App\Actions\Integrations\TestIntegrationConnectorAction;
use App\Enums\ConnectorKey;
use App\Enums\IntegrationActionType;
use App\Models\IntegrationAction;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\RequestType;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Integration hub')] class extends Component {
    use WithPagination;

    public string $connectorName = '';

    public string $connectorType = 'http';

    public string $actionName = '';

    public ?int $actionConnectorId = null;

    public string $actionType = 'invoke_webhook';

    public ?int $actionRequestTypeId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', IntegrationConnector::class);
    }

    #[Computed]
    public function connectors()
    {
        return IntegrationConnector::query()->withCount('actions')->latest()->get();
    }

    #[Computed]
    public function actions()
    {
        return IntegrationAction::query()->with(['connector', 'requestType:id,name'])->latest()->get();
    }

    #[Computed]
    public function deliveries()
    {
        return IntegrationDelivery::query()
            ->with(['action.connector', 'submission:id,reference_number,title'])
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function requestTypes()
    {
        return RequestType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function addConnector(): void
    {
        $this->authorize('create', IntegrationConnector::class);
        $this->validate([
            'connectorName' => ['required', 'string', 'max:120'],
            'connectorType' => ['required', \Illuminate\Validation\Rule::enum(ConnectorKey::class)],
        ]);

        IntegrationConnector::create([
            'name' => $this->connectorName,
            'type' => $this->connectorType,
            'is_enabled' => false,
        ]);

        $this->reset('connectorName');
        Flux::toast(__('Connector added. Save credentials, then enable it.'), variant: 'success');
    }

    public function toggleConnector(int $id): void
    {
        $connector = IntegrationConnector::query()->findOrFail($id);
        $this->authorize('update', $connector);
        $connector->update(['is_enabled' => ! $connector->is_enabled]);
    }

    public function testConnector(int $id, TestIntegrationConnectorAction $test): void
    {
        $connector = IntegrationConnector::query()->findOrFail($id);
        $this->authorize('update', $connector);
        $health = $test->execute($connector);
        Flux::toast($health->message, variant: $health->ok ? 'success' : 'danger');
    }

    public function addAction(): void
    {
        $this->authorize('create', IntegrationConnector::class);
        $this->validate([
            'actionName' => ['required', 'string', 'max:120'],
            'actionConnectorId' => ['required', 'exists:integration_connectors,id'],
            'actionType' => ['required', \Illuminate\Validation\Rule::enum(IntegrationActionType::class)],
            'actionRequestTypeId' => ['nullable', 'exists:request_types,id'],
        ]);

        IntegrationAction::create([
            'name' => $this->actionName,
            'connector_id' => $this->actionConnectorId,
            'action' => $this->actionType,
            'request_type_id' => $this->actionRequestTypeId,
            'is_enabled' => true,
        ]);

        $this->reset('actionName', 'actionRequestTypeId');
        Flux::toast(__('Post-approval action added.'), variant: 'success');
    }

    public function toggleAction(int $id): void
    {
        $action = IntegrationAction::query()->findOrFail($id);
        $this->authorize('update', $action->connector);
        $action->update(['is_enabled' => ! $action->is_enabled]);
    }

    public function replay(int $id, ReplayIntegrationDeliveryAction $replay): void
    {
        $delivery = IntegrationDelivery::query()->findOrFail($id);
        $this->authorize('update', $delivery->action->connector);

        try {
            $replay->execute($delivery);
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'replay' => $exception->getMessage(),
            ]);
        }

        Flux::toast(__('Delivery queued again.'), variant: 'success');
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-1">
                <flux:heading size="lg">{{ __('Integration hub') }}</flux:heading>
                <x-ui.help-link article="integrations" />
            </div>
            <flux:subheading>{{ __('Connectors, post-approval actions, and delivery history.') }}</flux:subheading>
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success">{{ session('status') }}</flux:callout>
    @endif

    <flux:card>
        <flux:heading size="md">{{ __('Connectors') }}</flux:heading>
        <form wire:submit="addConnector" class="mt-4 grid gap-3 sm:grid-cols-3">
            <flux:input wire:model="connectorName" :label="__('Name')" />
            <flux:select wire:model="connectorType" :label="__('Type')">
                @foreach (\App\Enums\ConnectorKey::cases() as $type)
                    <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex items-end">
                <flux:button type="submit" variant="primary">{{ __('Add connector') }}</flux:button>
            </div>
        </form>

        <div class="mt-6 space-y-4">
            @forelse ($this->connectors as $connector)
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="c-{{ $connector->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-medium">{{ $connector->name }}</p>
                            <p class="text-xs text-zinc-500">{{ $connector->type->label() }} · {{ $connector->actions_count }} {{ __('actions') }}</p>
                        </div>
                        <div class="flex gap-2">
                            <flux:button size="sm" wire:click="toggleConnector({{ $connector->id }})">
                                {{ $connector->is_enabled ? __('Disable') : __('Enable') }}
                            </flux:button>
                            <flux:button size="sm" variant="primary" wire:click="testConnector({{ $connector->id }})">{{ __('Test connection') }}</flux:button>
                        </div>
                    </div>
                    @if ($connector->last_error)
                        <p class="mt-2 text-xs text-red-600">{{ $connector->last_error }}</p>
                    @elseif ($connector->last_tested_at)
                        <p class="mt-2 text-xs text-zinc-500">{{ __('Last tested') }} {{ $connector->last_tested_at->diffForHumans() }}</p>
                    @endif
                    <form method="POST" action="{{ route('admin.integrations.credentials', $connector) }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                        @csrf
                        @foreach ($connector->type->credentialFields() as $field)
                            <flux:input type="password" name="{{ $field }}" :label="str_replace('_', ' ', $field)" autocomplete="off" placeholder="{{ isset($connector->credentialMap()[$field]) ? '••••••••' : '' }}" />
                        @endforeach
                        <div class="flex items-end gap-2">
                            <flux:button type="submit" size="sm">{{ __('Save credentials') }}</flux:button>
                            <flux:button type="submit" size="sm" name="clear" value="1" variant="danger">{{ __('Clear') }}</flux:button>
                        </div>
                    </form>
                    <p class="mt-2 text-xs text-zinc-500">{{ __('Tokens are encrypted at rest and never shown again. Empty fields keep the current secret.') }}</p>
                </div>
            @empty
                <p class="text-sm text-zinc-500">{{ __('No connectors yet.') }}</p>
            @endforelse
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="md">{{ __('Post-approval actions') }}</flux:heading>
        <form wire:submit="addAction" class="mt-4 grid gap-3 sm:grid-cols-4">
            <flux:input wire:model="actionName" :label="__('Name')" />
            <flux:select wire:model="actionConnectorId" :label="__('Connector')">
                <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                @foreach ($this->connectors as $connector)
                    <flux:select.option value="{{ $connector->id }}">{{ $connector->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="actionType" :label="__('Action')">
                @foreach (\App\Enums\IntegrationActionType::cases() as $type)
                    <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="actionRequestTypeId" :label="__('Request type (optional)')">
                <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                @foreach ($this->requestTypes as $type)
                    <flux:select.option value="{{ $type->id }}">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="sm:col-span-4">
                <flux:button type="submit" variant="primary">{{ __('Add action') }}</flux:button>
            </div>
        </form>
        <flux:table class="mt-4">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Connector') }}</flux:table.column>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->actions as $action)
                    <flux:table.row :key="'a-'.$action->id">
                        <flux:table.cell>{{ $action->name }}</flux:table.cell>
                        <flux:table.cell>{{ $action->connector->name }} · {{ $action->action->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $action->requestType?->name ?? __('All types') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="toggleAction({{ $action->id }})">{{ $action->is_enabled ? __('On') : __('Off') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:card>
        <flux:heading size="md">{{ __('Deliveries') }}</flux:heading>
        <flux:table class="mt-4">
            <flux:table.columns>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column>{{ __('Submission') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->deliveries as $delivery)
                    <flux:table.row :key="'d-'.$delivery->id">
                        <flux:table.cell>{{ $delivery->created_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>{{ $delivery->submission?->reference_number }} · {{ $delivery->action?->name }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $delivery->status->value }}
                            @if ($delivery->last_error)
                                <p class="text-xs text-red-600">{{ $delivery->last_error }}</p>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="replay({{ $delivery->id }})">{{ __('Replay') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        {{ $this->deliveries->links() }}
    </flux:card>
</div>
