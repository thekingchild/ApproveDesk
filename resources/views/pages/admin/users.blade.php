<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Users: the list and search. Display-name review, active-user capacity and
 * the add/edit dialog are child components in resources/views/livewire/admin/users
 * (ADR 0006, RV-ARC-08).
 */
new #[Title('User management')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    #[Computed]
    public function users(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return User::query()
            ->visibleTo(Auth::user())
            ->with(['department', 'roles.profile'])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $search): void {
                    $search->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(15);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createUser(): void
    {
        $this->authorize('create', User::class);
        $this->dispatch('open-user-form')->to('admin.users.user-form');
    }

    public function editUser(int $userId): void
    {
        $this->authorize('update', User::query()->findOrFail($userId));
        $this->dispatch('open-user-form', userId: $userId)->to('admin.users.user-form');
    }

    #[On('users-changed')]
    public function refreshUsers(): void
    {
        unset($this->users);
    }
} ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Users')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-1">
                <flux:heading size="lg">{{ __('Users') }}</flux:heading>
                <x-ui.help-link article="users-and-roles" />
            </div>
            <flux:subheading>{{ __('Manage access, roles, departments, and account status.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            @can('users.import')
                <flux:button icon="arrow-down-tray" :href="route('admin.users.import')" data-test="import-users">
                    {{ __('Import users') }}
                </flux:button>
            @endcan
            @can('create', User::class)
                <flux:button variant="primary" icon="user-plus" wire:click="createUser" data-test="create-user">
                    {{ __('Add user') }}
                </flux:button>
            @endcan
        </div>
    </div>

    @if (session('recovery-status'))
        <p class="text-sm text-emerald-700 dark:text-emerald-400" data-test="recovery-status">{{ session('recovery-status') }}</p>
    @endif

    <livewire:admin.users.name-changes />

    @can('updateActiveUserLimit', User::class)
        <livewire:admin.users.capacity />
    @endcan

    <div class="max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search name or email…')" />
    </div>

    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('User') }}</flux:table.column>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $user->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $user->email }}</div>
                        @if ($user->phone)
                            <div class="text-xs text-zinc-500">{{ $user->phone }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->department?->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($user->roles as $role)
                                <flux:badge color="zinc">{{ $role->profile?->label ?? str($role->name)->replace('-', ' ')->title() }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$user->is_active ? 'lime' : 'red'">
                            {{ $user->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-right">
                        @can('update', $user)
                            <flux:button size="xs" variant="ghost" wire:click="editUser({{ $user->id }})">{{ __('Edit') }}</flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No users found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <livewire:admin.users.user-form />
</div>
