<?php

use App\Actions\Privacy\AnonymizeUserAction;
use App\Actions\Users\CreateUserAction;
use App\Actions\Users\UpdateUserAction;
use App\Concerns\PasswordValidationRules;
use App\DTOs\UserData;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\GrantableRoleService;
use App\Services\Workflow\AssigneeDirectory;
use App\Support\E164Phone;
use App\Support\UserInputRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The add/edit user dialog on the Users page (ADR 0006 split, RV-ARC-08).
 * The page opens it with the open-user-form event; it announces users-changed
 * after a save so the list and capacity refresh.
 */
new class extends Component {
    use PasswordValidationRules;

    #[Locked]
    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $managerQuery = '';

    public string $departmentId = '';

    public string $managerId = '';

    /** @var array<int, string> */
    public array $roles = [];

    public bool $isActive = true;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    #[On('open-user-form')]
    public function open(?int $userId = null): void
    {
        $userId === null ? $this->create() : $this->edit($userId);
    }

    public function create(): void
    {
        $this->authorize('create', User::class);
        $this->resetForm();
        $this->modal('user-form')->show();
    }

    public function edit(int $userId): void
    {
        $user = User::query()->with('roles')->findOrFail($userId);
        $this->authorize('update', $user);

        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->departmentId = $user->department_id ? (string) $user->department_id : '';
        $this->managerId = $user->manager_id ? (string) $user->manager_id : '';
        $this->managerQuery = '';
        $this->roles = $user->roles->pluck('name')->all();
        $this->isActive = $user->is_active;
        $this->password = '';
        $this->password_confirmation = '';
        unset($this->editingUser);

        $this->modal('user-form')->show();
    }

    /** The person being edited, loaded once per request (RV-LAR-18). */
    #[Computed]
    public function editingUser(): ?User
    {
        return $this->editingUserId === null ? null : User::query()->find($this->editingUserId);
    }

    #[Computed]
    public function departments(): Collection
    {
        return Department::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function availableRoles(): Collection
    {
        return Role::query()
            ->whereIn('name', app(GrantableRoleService::class)->for(Auth::user()))
            ->with('profile')
            ->orderBy('name')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<string, string> */
    #[Computed]
    public function roleLabels(): \Illuminate\Support\Collection
    {
        return Role::query()
            ->whereIn('name', $this->roles)
            ->with('profile')
            ->get()
            ->mapWithKeys(fn (Role $role): array => [
                $role->name => $role->profile?->label ?? str($role->name)->replace('-', ' ')->title()->toString(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    #[Computed]
    public function managerResults(): \Illuminate\Support\Collection
    {
        $exclude = array_filter([(int) $this->editingUserId, (int) $this->managerId]);

        return app(AssigneeDirectory::class)->search('user', $this->managerQuery, $exclude);
    }

    /** Only people who can be picked as a manager; the owner account stays hidden (RV-ARC-08). */
    #[Computed]
    public function selectedManager(): ?User
    {
        return $this->managerId === '' ? null : User::query()->assignable()->find((int) $this->managerId);
    }

    public function chooseManager(int $userId): void
    {
        $this->managerId = (string) $userId;
        $this->managerQuery = '';
        unset($this->selectedManager, $this->managerResults);
    }

    public function clearManager(?int $userId = null): void
    {
        $this->managerId = '';
        $this->managerQuery = '';
        unset($this->selectedManager, $this->managerResults);
    }

    public function updatedManagerQuery(): void
    {
        unset($this->managerResults);
    }

    public function save(CreateUserAction $create, UpdateUserAction $update): void
    {
        $target = $this->editingUser;
        $this->authorize($target ? 'update' : 'create', $target ?? User::class);

        $validated = $this->validate(UserInputRules::form($target, $this->passwordRules()));

        $data = new UserData(
            name: $validated['name'],
            email: $validated['email'],
            phone: E164Phone::normalize($validated['phone'] ?? null),
            departmentId: ($validated['departmentId'] ?? '') === '' ? null : (int) $validated['departmentId'],
            roles: $validated['roles'],
            isActive: $validated['isActive'],
            password: $target === null ? $validated['password'] : null,
            managerId: ($validated['managerId'] ?? '') === '' ? null : (int) $validated['managerId'],
        );

        if ($target) {
            $update->execute($target, $data, Auth::user());
            $message = __('User updated.');
        } else {
            $create->execute($data, Auth::user());
            $message = __('User created.');
        }

        $this->resetForm();
        $this->modal('user-form')->close();
        $this->dispatch('users-changed');
        Flux::toast($message, variant: 'success');
    }

    public function anonymize(AnonymizeUserAction $anonymize): void
    {
        $target = $this->editingUser;
        abort_if($target === null, 404);
        $this->authorize('anonymize', $target);

        $anonymize->execute($target, Auth::user());

        $this->resetForm();
        $this->modal('user-form')->close();
        $this->dispatch('users-changed');
        Flux::toast(__('Account anonymized. Request history is still there.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingUserId', 'name', 'email', 'phone', 'departmentId', 'managerId', 'managerQuery', 'roles',
            'password', 'password_confirmation',
        ]);
        $this->isActive = true;
        unset($this->editingUser, $this->selectedManager, $this->managerResults);
    }
} ?>

<flux:modal name="user-form" class="md:w-[36rem]">
    @php($editingTarget = $this->editingUser)
    @php($identityLocked = $editingTarget !== null && Auth::id() !== $editingTarget->id && ! Auth::user()?->can('manageIdentity', $editingTarget))
    @php($editingSelf = $editingTarget !== null && Auth::id() === $editingTarget->id && ! Auth::user()?->can('system_owner'))
    <form wire:submit="save" class="space-y-5">
        <div>
            <flux:heading size="md">{{ $editingUserId ? __('Edit user') : __('Add user') }}</flux:heading>
            <flux:subheading>{{ __('Assign at least one role. New users are marked email-verified.') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" required />
            <div>
                <flux:input wire:model="email" type="email" :label="__('Email')" required :readonly="$identityLocked" data-test="user-email" />
                @if ($identityLocked)
                    <p class="mt-1 text-sm text-zinc-500" data-test="email-locked">{{ __('Only the System owner can change this person’s email, because they have more access than you.') }}</p>
                @endif
            </div>
            <div class="sm:col-span-2">
                <flux:input wire:model="phone" :label="__('Phone (international)')" placeholder="+2348012345678" data-test="user-phone" />
                <p class="mt-1 text-sm text-zinc-500">{{ __('Optional. Use E.164, starting with + and country code. Used later for SMS and WhatsApp.') }}</p>
                <flux:error name="phone" />
            </div>

            <div class="sm:col-span-2">
                <flux:select wire:model="departmentId" :label="__('Department')">
                    <flux:select.option value="">{{ __('No department') }}</flux:select.option>
                    @foreach ($this->departments as $department)
                        <flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:col-span-2">
                <flux:input type="hidden" wire:model="managerId" />
                <p class="text-sm font-medium">{{ __('Line manager') }}</p>
                <x-ui.assignee-picker
                    type="user"
                    query="managerQuery"
                    :selected="collect(array_filter([$this->selectedManager]))"
                    :results="$this->managerResults"
                    add="chooseManager"
                    remove="clearManager"
                />
                <p class="mt-1 text-sm text-zinc-500">{{ __("Used when a workflow step assigns the requester's manager.") }}</p>
                <flux:error name="managerId" />
            </div>

            <div class="sm:col-span-2">
                @if ($editingSelf)
                    <p class="text-sm font-medium">{{ __('Roles') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1" data-test="self-roles-read-only">
                        @foreach ($roles as $role)
                            <flux:badge color="zinc">{{ $this->roleLabels->get($role, str($role)->replace('-', ' ')->title()) }}</flux:badge>
                        @endforeach
                    </div>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('You cannot change your own roles.') }}</p>
                @else
                    <flux:checkbox.group wire:model="roles" :label="__('Roles')" class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->availableRoles as $role)
                            <flux:checkbox :value="$role->name" :label="$role->profile?->label ?? str($role->name)->replace('-', ' ')->title()" />
                        @endforeach
                    </flux:checkbox.group>
                    @php($preservedRoles = array_diff($roles, $this->availableRoles->pluck('name')->all()))
                    @if ($editingUserId && $preservedRoles !== [])
                        <div class="mt-3" data-test="preserved-roles-read-only">
                            <p class="text-sm text-zinc-500">{{ __('Roles you cannot remove:') }}</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($preservedRoles as $role)
                                    <flux:badge color="zinc">{{ $this->roleLabels->get($role, str($role)->replace('-', ' ')->title()) }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
                <flux:error name="roles" />
            </div>

            @if (! $editingUserId)
                <flux:input wire:model="password" type="password" :label="__('Password')" required viewable />
                <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm password')" required viewable />
            @endif

            <div class="sm:col-span-2">
                @if ($editingSelf)
                    <p class="text-sm font-medium">{{ __('Account status') }}</p>
                    <flux:badge class="mt-2" :color="$isActive ? 'lime' : 'red'" data-test="self-active-read-only">
                        {{ $isActive ? __('Active') : __('Inactive') }}
                    </flux:badge>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('You cannot change your own account status.') }}</p>
                @elseif ($identityLocked)
                    <p class="text-sm font-medium">{{ __('Account status') }}</p>
                    <flux:badge class="mt-2" :color="$isActive ? 'lime' : 'red'" data-test="active-locked">
                        {{ $isActive ? __('Active') : __('Inactive') }}
                    </flux:badge>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('Only the System owner can change this person’s account status.') }}</p>
                @else
                    <flux:checkbox wire:model="isActive" :label="__('Account is active')" />
                @endif
                <flux:error name="isActive" />
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            @if ($editingTarget && Auth::user()?->can('anonymize', $editingTarget))
                <flux:button type="button" variant="danger" wire:click="anonymize" wire:confirm="{{ __('Remove this person’s name and email? Decisions they recorded stay on the requests.') }}" data-test="anonymize-user">
                    {{ __('Anonymize account') }}
                </flux:button>
            @endif
            <flux:button type="submit" variant="primary" data-test="save-user">{{ __('Save user') }}</flux:button>
        </div>
    </form>
    @if ($editingTarget && Auth::user()?->can('recover', $editingTarget))
        <div class="mt-6 space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="account-recovery">
            <flux:heading size="sm">{{ __('Account recovery') }}</flux:heading>
            <flux:text>{{ __('These actions sign the person out everywhere. They do not change roles or request history.') }}</flux:text>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.users.recovery.password', $editingTarget) }}">
                    @csrf
                    <flux:button type="submit" variant="danger" data-test="recover-password" data-confirm="{{ __('Invalidate this password and email a reset link?') }}">
                        {{ __('Invalidate password and email reset link') }}
                    </flux:button>
                </form>
                <form method="POST" action="{{ route('admin.users.recovery.second-factor', $editingTarget) }}">
                    @csrf
                    <flux:button type="submit" variant="danger" data-test="recover-second-factor" data-confirm="{{ __('Remove the authenticator, recovery codes, and passkeys?') }}">
                        {{ __('Remove authenticator, recovery codes, and passkeys') }}
                    </flux:button>
                </form>
            </div>
        </div>
    @endif
</flux:modal>
