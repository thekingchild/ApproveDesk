<?php

use App\Actions\Webhooks\CreateWebhookEndpointAction;
use App\Actions\Webhooks\DeleteWebhookEndpointAction;
use App\Actions\Webhooks\UpdateWebhookEndpointAction;
use App\DTOs\WebhookEndpointData;
use App\Enums\WebhookEvent;
use App\Models\WebhookEndpoint;
use App\Rules\ValidWebhookUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Webhook endpoints')] class extends Component {
    use WithPagination;

    public string $search = '';

    public ?int $editingEndpointId = null;

    public string $name = '';

    public string $url = '';

    /** @var array<int, string> */
    public array $events = [];

    public bool $isActive = true;

    public bool $rotateSecret = false;

    public ?string $newSecret = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WebhookEndpoint::class);
    }

    #[Computed]
    public function endpoints(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return WebhookEndpoint::query()
            ->with(['creator:id,name', 'latestDelivery'])
            ->when($this->search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('url', 'like', '%'.$this->search.'%')))
            ->latest()
            ->paginate(15);
    }

    /** @return array<int, WebhookEvent> */
    #[Computed]
    public function availableEvents(): array
    {
        return WebhookEvent::cases();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createEndpoint(): void
    {
        $this->authorize('create', WebhookEndpoint::class);
        $this->resetForm();
        $this->modal('webhook-form')->show();
    }

    public function editEndpoint(int $endpointId): void
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($endpointId);
        $this->authorize('update', $endpoint);

        $this->resetValidation();
        $this->newSecret = null;
        $this->editingEndpointId = $endpoint->id;
        $this->name = $endpoint->name;
        $this->url = $endpoint->url;
        $this->events = $endpoint->events;
        $this->isActive = $endpoint->is_active;
        $this->rotateSecret = false;
        $this->modal('webhook-form')->show();
    }

    public function save(): void
    {
        $target = $this->editingEndpointId === null ? null : WebhookEndpoint::query()->findOrFail($this->editingEndpointId);
        $this->authorize($target === null ? 'create' : 'update', $target ?? WebhookEndpoint::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', new ValidWebhookUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', Rule::enum(WebhookEvent::class)],
            'isActive' => ['boolean'],
            'rotateSecret' => ['boolean'],
        ]);

        $data = new WebhookEndpointData(
            name: $validated['name'],
            url: $validated['url'],
            events: $validated['events'],
            isActive: $validated['isActive'],
        );

        if ($target === null) {
            ['secret' => $secret] = app(CreateWebhookEndpointAction::class)->execute($data, auth()->user());
            $this->newSecret = $secret;
            $message = 'Webhook endpoint created. Copy its signing secret now.';
        } else {
            $this->newSecret = app(UpdateWebhookEndpointAction::class)->execute($target, $data, $validated['rotateSecret']);
            $message = $this->newSecret === null ? 'Webhook endpoint updated.' : 'Webhook endpoint updated and signing secret rotated.';
        }

        unset($this->endpoints);
        $this->modal('webhook-form')->close();
        Flux::toast($message, variant: 'success');
    }

    public function deleteEndpoint(int $endpointId): void
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($endpointId);
        $this->authorize('delete', $endpoint);

        app(DeleteWebhookEndpointAction::class)->execute($endpoint);
        unset($this->endpoints);
        Flux::toast('Webhook endpoint deleted.', variant: 'success');
    }

    public function dismissSecret(): void
    {
        $this->newSecret = null;
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingEndpointId', 'name', 'url', 'events', 'rotateSecret', 'newSecret']);
        $this->isActive = true;
    }
} ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Webhooks')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-1">
                <flux:heading size="lg">{{ __('Webhook endpoints') }}</flux:heading>
                <x-ui.help-link article="webhooks" />
            </div>
            <flux:subheading>{{ __('Send signed workflow events to trusted HTTPS integrations.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="createEndpoint" data-test="create-webhook">
            {{ __('Add endpoint') }}
        </flux:button>
    </div>

    @if ($newSecret)
        <flux:callout variant="warning" icon="key" heading="{{ __('Copy this signing secret now') }}">
            <div class="space-y-2">
                <p>{{ __('It will not be displayed again. Verify X-ApproveDesk-Signature as HMAC-SHA256 of timestamp, a period, and the raw JSON request body.') }}</p>
                <code class="block break-all rounded bg-zinc-950 p-3 text-xs text-white">{{ $newSecret }}</code>
                <flux:button size="sm" variant="ghost" wire:click="dismissSecret">{{ __('I copied it') }}</flux:button>
            </div>
        </flux:callout>
    @endif

    <div class="max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search endpoints…')" />
    </div>

    <flux:table :paginate="$this->endpoints">
        <flux:table.columns>
            <flux:table.column>{{ __('Endpoint') }}</flux:table.column>
            <flux:table.column>{{ __('Events') }}</flux:table.column>
            <flux:table.column>{{ __('Last delivery') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->endpoints as $endpoint)
                <flux:table.row :key="$endpoint->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $endpoint->name }}</div>
                        <div class="max-w-xs truncate text-xs text-zinc-500">{{ $endpoint->url }}</div>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-xs">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($endpoint->events as $event)
                                <flux:badge size="sm" color="zinc">{{ $event }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($endpoint->latestDelivery)
                            <div>{{ str($endpoint->latestDelivery->status->value)->headline() }}</div>
                            <div class="text-xs text-zinc-500">{{ $endpoint->latestDelivery->updated_at->diffForHumans() }}</div>
                        @else
                            <span class="text-zinc-500">{{ __('No deliveries yet') }}</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$endpoint->is_active ? 'green' : 'zinc'">{{ $endpoint->is_active ? __('Active') : __('Paused') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-right">
                        <flux:button size="xs" variant="ghost" wire:click="editEndpoint({{ $endpoint->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="deleteEndpoint({{ $endpoint->id }})" wire:confirm="{{ __('Delete this webhook endpoint and its delivery history?') }}">{{ __('Delete') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No webhook endpoints found.') }}</flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="webhook-form" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="md">{{ $editingEndpointId ? __('Edit webhook endpoint') : __('Add webhook endpoint') }}</flux:heading>
                <flux:subheading>{{ __('Each delivery is an HTTPS POST signed with this endpoint’s secret.') }}</flux:subheading>
            </div>
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="url" type="url" :label="__('HTTPS URL')" placeholder="https://example.com/webhooks/approvedesk" required />
            <flux:checkbox.group wire:model="events" :label="__('Events')" class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->availableEvents as $event)
                    <flux:checkbox :value="$event->value" :label="$event->value" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="events" />
            <flux:checkbox wire:model="isActive" :label="__('Endpoint is active')" />
            @if ($editingEndpointId)
                <flux:checkbox wire:model="rotateSecret" :label="__('Rotate signing secret')" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-webhook">{{ __('Save endpoint') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
