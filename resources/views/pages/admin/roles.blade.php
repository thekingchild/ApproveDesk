<?php

use App\Models\Role;
use App\Models\RoleProfile;
use App\Services\Roles\RolePermissionService;
use App\Support\PermissionCatalogue;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roles & permissions')] class extends Component {
    #[Locked]
    public ?int $editingRoleId = null;

    public string $label = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return RoleProfile::query()
            ->whereHas('role', fn ($query) => $query->where('name', '!=', \App\Enums\SystemRole::SuperAdmin->value))
            ->with(['role' => fn ($query) => $query->withCount(['users', 'permissions'])])
            ->orderBy('is_archived')
            ->orderByDesc('is_builtin')
            ->orderBy('label')
            ->get();
    }

    /** @return array<string, list<array<string, mixed>>> */
    #[Computed]
    public function permissionGroups(): array
    {
        $ceiling = app(RolePermissionService::class)->ceiling(Auth::user());

        return collect(PermissionCatalogue::grouped())->map(
            fn (array $entries): array => array_map(
                fn (array $entry): array => [...$entry, 'available' => in_array($entry['key'], $ceiling, true)],
                $entries,
            ),
        )->all();
    }

    public function createRole(): void
    {
        $this->authorize('create', Role::class);
        $this->resetForm();
        $this->modal('role-editor')->show();
    }

    public function editRole(int $roleId): void
    {
        $role = Role::query()->with(['profile', 'permissions'])->findOrFail($roleId);
        $this->authorize('update', $role);
        $this->editingRoleId = $role->id;
        $this->label = $role->profile->label;
        $this->description = $role->profile->description ?? '';
        $this->permissions = $role->permissions->pluck('name')->all();
        $this->resetValidation();
        $this->modal('role-editor')->show();
    }

    public function save(): void
    {
        $role = $this->editingRoleId ? Role::query()->findOrFail($this->editingRoleId) : null;
        $this->authorize($role ? 'update' : 'create', $role ?? Role::class);
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:'.implode(',', PermissionCatalogue::keys())],
        ]);
        $service = app(RolePermissionService::class);
        if ($role) {
            $service->update(Auth::user(), $role, $validated['label'], $validated['description'] ?: null, $validated['permissions']);
        } else {
            $service->create(Auth::user(), $validated['label'], $validated['description'] ?: null, $validated['permissions']);
        }
        $this->resetForm();
        unset($this->roles);
        $this->modal('role-editor')->close();
        Flux::toast(__('Role saved.'), variant: 'success');
    }

    public function cloneRole(int $roleId): void
    {
        $role = Role::query()->with('profile')->findOrFail($roleId);
        $this->authorize('clone', $role);
        app(RolePermissionService::class)->clone(Auth::user(), $role, __('Copy of :role', ['role' => $role->profile->label]));
        unset($this->roles);
        Flux::toast(__('Role cloned.'), variant: 'success');
    }

    public function archiveRole(int $roleId): void
    {
        $role = Role::query()->findOrFail($roleId);
        $this->authorize('archive', $role);
        app(RolePermissionService::class)->archive(Auth::user(), $role);
        unset($this->roles);
        Flux::toast(__('Role archived. Existing assignments remain effective.'), variant: 'success');
    }

    public function restoreRole(int $roleId): void
    {
        $role = Role::query()->findOrFail($roleId);
        $this->authorize('restore', $role);
        app(RolePermissionService::class)->restore(Auth::user(), $role);
        unset($this->roles);
        Flux::toast(__('Role restored.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingRoleId', 'label', 'description', 'permissions']);
    }
}; ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Roles & permissions')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-1">
                <flux:heading size="lg">{{ __('Roles & permissions') }}</flux:heading>
                <x-ui.help-link article="users-and-roles" />
            </div>
            <flux:subheading>{{ __('Build feature-oriented roles without exceeding your own authority.') }}</flux:subheading>
        </div>
        @can('create', Role::class)
            <flux:button variant="primary" icon="plus" wire:click="createRole" data-test="create-role">{{ __('Create custom role') }}</flux:button>
        @endcan
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Users') }}</flux:table.column>
            <flux:table.column>{{ __('Permissions') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->roles as $profile)
                <flux:table.row :key="$profile->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $profile->label }}</div>
                        <div class="text-xs text-zinc-500">{{ $profile->description ?: __('No description') }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-1">
                            <flux:badge :color="$profile->is_builtin ? 'blue' : 'zinc'">{{ $profile->is_builtin ? __('Built-in') : __('Custom') }}</flux:badge>
                            @if ($profile->is_archived)<flux:badge color="amber">{{ __('Archived') }}</flux:badge>@endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $profile->role->users_count }}</flux:table.cell>
                    <flux:table.cell>{{ $profile->role->permissions_count }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-1">
                            @can('clone', $profile->role)<flux:button size="xs" variant="ghost" wire:click="cloneRole({{ $profile->role_id }})">{{ __('Clone') }}</flux:button>@endcan
                            @can('update', $profile->role)<flux:button size="xs" variant="ghost" wire:click="editRole({{ $profile->role_id }})">{{ __('Edit') }}</flux:button>@endcan
                            @can('archive', $profile->role)<flux:button size="xs" variant="ghost" wire:click="archiveRole({{ $profile->role_id }})" wire:confirm="{{ __('Archive this role? Existing assignments remain effective.') }}">{{ __('Archive') }}</flux:button>@endcan
                            @can('restore', $profile->role)<flux:button size="xs" variant="ghost" wire:click="restoreRole({{ $profile->role_id }})">{{ __('Restore') }}</flux:button>@endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="role-editor" class="md:w-[52rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="md">{{ $editingRoleId ? __('Edit custom role') : __('Create custom role') }}</flux:heading>
                <flux:subheading>{{ __('Only capabilities within your permission ceiling can be selected.') }}</flux:subheading>
            </div>
            <flux:input wire:model="label" :label="__('Role name')" required />
            <flux:textarea wire:model="description" :label="__('Description')" />
            <flux:error name="permissions" />
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($this->permissionGroups as $group => $entries)
                    <flux:card class="space-y-3">
                        <flux:heading size="sm">{{ $group }}</flux:heading>
                        @foreach ($entries as $entry)
                            <div>
                                <flux:checkbox wire:model="permissions" :value="$entry['key']" :label="$entry['label']" :disabled="! $entry['available']" />
                                <p class="ml-7 text-xs text-zinc-500">{{ $entry['description'] }}</p>
                                @if ($entry['risk'] === 'elevated')<flux:badge class="ml-7 mt-1" color="amber">{{ __('Elevated') }}</flux:badge>@endif
                                @if (! $entry['available'])<p class="ml-7 text-xs text-amber-700">{{ __('Outside your permission ceiling') }}</p>@endif
                                @if ($entry['depends_on_any'] !== [])<p class="ml-7 text-xs text-zinc-500">{{ __('Works with: :permissions', ['permissions' => implode(', ', $entry['depends_on_any'])]) }}</p>@endif
                            </div>
                        @endforeach
                    </flux:card>
                @endforeach
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-role">{{ __('Save role') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
