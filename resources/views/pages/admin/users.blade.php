<?php

use App\Actions\Users\CreateUserAction;
use App\Actions\Users\UpdateUserAction;
use App\Concerns\PasswordValidationRules;
use App\DTOs\UserData;
use App\Models\Department;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Title('User management')] class extends Component {
    use PasswordValidationRules;
    use WithPagination;

    public string $search = '';

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $departmentId = '';

    /** @var array<int, string> */
    public array $roles = [];

    public bool $isActive = true;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    #[Computed]
    public function users(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return User::query()
            ->with(['department', 'roles'])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $search): void {
                    $search->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    #[Computed]
    public function availableRoles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()
            ->when(! Auth::user()->hasRole('super-admin'), fn (Builder $query) => $query->where('name', '!=', 'super-admin'))
            ->orderBy('name')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createUser(): void
    {
        $this->authorize('create', User::class);
        $this->resetForm();
        $this->modal('user-form')->show();
    }

    public function editUser(int $userId): void
    {
        $user = User::query()->with('roles')->findOrFail($userId);
        $this->authorize('update', $user);

        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->departmentId = $user->department_id ? (string) $user->department_id : '';
        $this->roles = $user->roles->pluck('name')->all();
        $this->isActive = $user->is_active;
        $this->password = '';
        $this->password_confirmation = '';

        $this->modal('user-form')->show();
    }

    public function save(): void
    {
        $target = $this->editingUserId
            ? User::query()->findOrFail($this->editingUserId)
            : null;

        $this->authorize($target ? 'update' : 'create', $target ?? User::class);

        $passwordRules = $target
            ? ['nullable', 'string', \Illuminate\Validation\Rules\Password::default(), 'confirmed']
            : $this->passwordRules();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($target)],
            'departmentId' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::exists(Role::class, 'name')],
            'isActive' => ['required', 'boolean'],
            'password' => $passwordRules,
        ]);

        $data = new UserData(
            name: $validated['name'],
            email: $validated['email'],
            departmentId: $validated['departmentId'] === '' ? null : (int) $validated['departmentId'],
            roles: $validated['roles'],
            isActive: $validated['isActive'],
            password: $validated['password'] !== '' ? $validated['password'] : null,
        );

        if ($target) {
            app(UpdateUserAction::class)->execute($target, $data, Auth::user());
            $message = 'User updated.';
        } else {
            app(CreateUserAction::class)->execute($data, Auth::user());
            $message = 'User created.';
        }

        $this->resetForm();
        unset($this->users);
        $this->modal('user-form')->close();
        Flux::toast($message, variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingUserId', 'name', 'email', 'departmentId', 'roles',
            'password', 'password_confirmation',
        ]);
        $this->isActive = true;
    }
} ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('Manage access, roles, departments, and account status.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="createUser" data-test="create-user">
            {{ __('Add user') }}
        </flux:button>
    </div>

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
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->department?->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($user->roles as $role)
                                <flux:badge color="zinc">{{ $role->name }}</flux:badge>
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

    <flux:modal name="user-form" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="md">{{ $editingUserId ? __('Edit user') : __('Add user') }}</flux:heading>
                <flux:subheading>{{ __('Assign at least one role. New users are marked email-verified.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />

                <div class="sm:col-span-2">
                    <flux:select wire:model="departmentId" :label="__('Department')">
                        <flux:select.option value="">{{ __('No department') }}</flux:select.option>
                        @foreach ($this->departments as $department)
                            <flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="sm:col-span-2">
                    <flux:checkbox.group wire:model="roles" :label="__('Roles')" class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->availableRoles as $role)
                            <flux:checkbox :value="$role->name" :label="str($role->name)->replace('-', ' ')->title()" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="roles" />
                </div>

                <flux:input wire:model="password" type="password" :label="$editingUserId ? __('New password (optional)') : __('Password')" :required="! $editingUserId" viewable />
                <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm password')" :required="! $editingUserId" viewable />

                <div class="sm:col-span-2">
                    <flux:checkbox wire:model="isActive" :label="__('Account is active')" />
                    <flux:error name="isActive" />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-user">{{ __('Save user') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
