<?php

use App\Actions\Settings\UpdateActiveUserLimitAction;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Active-user capacity on the Users page; System owner only (ADR 0006 split).
 */
new class extends Component {
    public bool $unlimitedUsers = true;

    public string $maxActiveUsers = '';

    public function mount(ActiveUserLimitService $limits): void
    {
        $this->authorize('updateActiveUserLimit', User::class);
        $maximum = $limits->maximum();
        $this->unlimitedUsers = $maximum === null;
        $this->maxActiveUsers = $maximum === null ? '' : (string) $maximum;
    }

    /** @return array{active: int, maximum: int|null, remaining: int|null, inactive: int} */
    #[Computed]
    public function capacity(): array
    {
        $limits = app(ActiveUserLimitService::class);

        return [
            'active' => $limits->activeCount(),
            'maximum' => $limits->maximum(),
            'remaining' => $limits->remaining(),
            'inactive' => User::query()->where('is_active', false)->count(),
        ];
    }

    #[On('users-changed')]
    public function refreshCounts(): void
    {
        unset($this->capacity);
    }

    public function save(UpdateActiveUserLimitAction $update): void
    {
        $this->authorize('updateActiveUserLimit', User::class);

        $validated = $this->validate([
            'unlimitedUsers' => ['boolean'],
            'maxActiveUsers' => [$this->unlimitedUsers ? 'nullable' : 'required', 'integer', 'min:1'],
        ]);

        $update->execute(! empty($validated['unlimitedUsers']) ? null : (int) $validated['maxActiveUsers'], Auth::user());

        unset($this->capacity);
        Flux::toast(__('Active-user capacity saved.'), variant: 'success');
    }
} ?>

<flux:card class="space-y-4" data-test="active-user-capacity">
    <div>
        <flux:heading size="sm">{{ __('Active-user capacity') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Active users: :active. Maximum: :maximum. Remaining: :remaining. Inactive accounts: :inactive. The System owner is included in the active count.', [
                'active' => $this->capacity['active'],
                'maximum' => $this->capacity['maximum'] ?? __('Unlimited'),
                'remaining' => $this->capacity['remaining'] ?? __('Unlimited'),
                'inactive' => $this->capacity['inactive'],
            ]) }}
        </flux:text>
    </div>
    <form wire:submit="save" class="grid gap-3 md:grid-cols-3">
        <flux:checkbox wire:model.live="unlimitedUsers" :label="__('Leave unlimited')" />
        <flux:input wire:model="maxActiveUsers" type="number" min="1" :label="__('Maximum active users')" :disabled="$unlimitedUsers" />
        <div class="flex items-end">
            <flux:button type="submit" data-test="save-capacity">{{ __('Save capacity') }}</flux:button>
        </div>
    </form>
    <flux:error name="maxActiveUsers" />
</flux:card>
