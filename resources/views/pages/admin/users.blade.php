<?php

use App\Actions\Users\CreateUserAction;
use App\Actions\Users\DecideDisplayNameChangeAction;
use App\Actions\Users\UpdateUserAction;
use App\Actions\Privacy\AnonymizeUserAction;
use App\Actions\Settings\UpdateActiveUserLimitAction;
use App\Concerns\PasswordValidationRules;
use App\DTOs\UserData;
use App\Models\Department;
use App\Models\DisplayNameChange;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use App\Services\Users\GrantableRoleService;
use App\Services\Workflow\AssigneeDirectory;
use App\Support\E164Phone;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('User management')] class extends Component {
    use PasswordValidationRules;
    use WithPagination;

    public string $search = '';

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

    public bool $unlimitedUsers = true;

    public string $maxActiveUsers = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
        $maximum = app(ActiveUserLimitService::class)->maximum();
        $this->unlimitedUsers = $maximum === null;
        $this->maxActiveUsers = $maximum === null ? '' : (string) $maximum;
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

    #[Computed]
    public function pendingNameChanges(): \Illuminate\Database\Eloquent\Collection
    {
        return DisplayNameChange::query()
            ->where('status', 'pending')
            ->with('user')
            ->latest()
            ->get();
    }

    public function approveNameChange(int $changeId): void
    {
        app(DecideDisplayNameChangeAction::class)->approve(Auth::user(), DisplayNameChange::query()->findOrFail($changeId));
        unset($this->pendingNameChanges, $this->users);
        Flux::toast(__('Display name approved.'), variant: 'success');
    }

    public function rejectNameChange(int $changeId, ?string $reason = null): void
    {
        $reason = trim((string) $reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw \Illuminate\Validation\ValidationException::withMessages(['decisionReason' => __('Give a reason of no more than 1000 characters.')]);
        }

        app(DecideDisplayNameChangeAction::class)->reject(Auth::user(), DisplayNameChange::query()->findOrFail($changeId), $reason);
        unset($this->pendingNameChanges);
        Flux::toast(__('Display name rejected.'), variant: 'success');
    }

    #[Computed]
    public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public function managerResults(): \Illuminate\Support\Collection
    {
        $exclude = array_filter([(int) $this->editingUserId, (int) $this->managerId]);

        return app(AssigneeDirectory::class)->search('user', $this->managerQuery, $exclude);
    }

    public function selectedManager(): ?User
    {
        if ($this->managerId === '') {
            return null;
        }

        return User::query()->find((int) $this->managerId);
    }

    public function chooseManager(int $userId): void
    {
        $this->managerId = (string) $userId;
        $this->managerQuery = '';
    }

    public function clearManager(?int $userId = null): void
    {
        $this->managerId = '';
        $this->managerQuery = '';
    }

    #[Computed]
    public function availableRoles(): \Illuminate\Database\Eloquent\Collection
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
        $this->phone = $user->phone ?? '';
        $this->departmentId = $user->department_id ? (string) $user->department_id : '';
        $this->managerId = $user->manager_id ? (string) $user->manager_id : '';
        $this->managerQuery = '';
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

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($target)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique(User::class, 'phone')->ignore($target)],
            'departmentId' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'managerId' => ['nullable', 'integer', Rule::exists(User::class, 'id'), Rule::notIn([$target?->id])],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::exists(Role::class, 'name')],
            'isActive' => ['required', 'boolean'],
        ];
        if ($target === null) {
            $rules['password'] = $this->passwordRules();
        }

        $validated = $this->validate($rules);

        if (($validated['managerId'] ?? '') !== ''
            && ! User::query()->assignable()->whereKey((int) $validated['managerId'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'managerId' => __('Choose an available line manager.'),
            ]);
        }

        $phone = E164Phone::normalize($validated['phone'] ?? null);
        if (($validated['phone'] ?? '') !== '' && $validated['phone'] !== null && ! E164Phone::isValid($phone)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => __('Enter a phone number in international format, such as +2348012345678.'),
            ]);
        }

        $data = new UserData(
            name: $validated['name'],
            email: $validated['email'],
            phone: $phone,
            departmentId: $validated['departmentId'] === '' ? null : (int) $validated['departmentId'],
            roles: $validated['roles'],
            isActive: $validated['isActive'],
            password: $target === null ? $validated['password'] : null,
            managerId: ($validated['managerId'] ?? '') === '' ? null : (int) $validated['managerId'],
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

    public function anonymizeUser(int $userId): void
    {
        $target = User::query()->findOrFail($userId);
        $this->authorize('anonymize', $target);

        app(AnonymizeUserAction::class)->execute($target, Auth::user());

        $this->resetForm();
        unset($this->users);
        $this->modal('user-form')->close();
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
    }

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

    public function saveCapacity(): void
    {
        $this->authorize('updateActiveUserLimit', User::class);

        $validated = $this->validate([
            'unlimitedUsers' => ['boolean'],
            'maxActiveUsers' => [$this->unlimitedUsers ? 'nullable' : 'required', 'integer', 'min:1'],
        ]);

        app(UpdateActiveUserLimitAction::class)->execute(
            ! empty($validated['unlimitedUsers']) ? null : (int) $validated['maxActiveUsers'],
            Auth::user(),
        );

        unset($this->capacity);
        Flux::toast(__('Active-user capacity saved.'), variant: 'success');
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

    @if ($this->pendingNameChanges->isNotEmpty())
        <flux:card class="space-y-4" data-test="display-name-review">
            <div>
                <flux:heading size="sm">{{ __('Display-name review') }}</flux:heading>
                <flux:text>{{ __('Names remain unchanged until a different authorized reviewer approves them.') }}</flux:text>
            </div>
            @foreach ($this->pendingNameChanges as $change)
                <div class="grid gap-3 border-t border-zinc-200 pt-4 md:grid-cols-[1fr_1fr_auto] dark:border-zinc-700" wire:key="name-change-{{ $change->id }}">
                    <div><div class="font-medium">{{ $change->user->name }}</div><div class="text-xs text-zinc-500">{{ $change->user->email }}</div></div>
                    <div><div class="text-xs text-zinc-500">{{ __('Requested name') }}</div><div class="font-medium">{{ $change->proposed_name }}</div></div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! Auth::user()->is($change->user) && Auth::user()->can('update', $change->user))
                            <flux:button size="xs" variant="primary" wire:click="approveNameChange({{ $change->id }})">{{ __('Approve') }}</flux:button>
                            <flux:button size="xs" variant="danger" wire:click="rejectNameChange({{ $change->id }}, prompt('Reason for rejection'))">{{ __('Reject') }}</flux:button>
                        @else
                            <flux:badge color="amber">{{ __('Another authorized reviewer must decide') }}</flux:badge>
                        @endif
                    </div>
                </div>
            @endforeach
        </flux:card>
    @endif

    @if (Auth::user()?->isSystemOwner())
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
            <form wire:submit="saveCapacity" class="grid gap-3 md:grid-cols-3">
                <flux:checkbox wire:model.live="unlimitedUsers" :label="__('Leave unlimited')" />
                <flux:input wire:model="maxActiveUsers" type="number" min="1" :label="__('Maximum active users')" :disabled="$unlimitedUsers" />
                <div class="flex items-end">
                    <flux:button type="submit" data-test="save-capacity">{{ __('Save capacity') }}</flux:button>
                </div>
            </form>
            <flux:error name="maxActiveUsers" />
        </flux:card>
    @endif

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

    <flux:modal name="user-form" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="md">{{ $editingUserId ? __('Edit user') : __('Add user') }}</flux:heading>
                <flux:subheading>{{ __('Assign at least one role. New users are marked email-verified.') }}</flux:subheading>
            </div>

            @php($editingTarget = $editingUserId ? User::query()->find($editingUserId) : null)
            @php($identityLocked = $editingTarget !== null && Auth::id() !== $editingTarget->id && ! Auth::user()?->can('manageIdentity', $editingTarget))
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
                        :selected="collect(array_filter([$this->selectedManager()]))"
                        :results="$this->managerResults()"
                        add="chooseManager"
                        remove="clearManager"
                    />
                    <p class="mt-1 text-sm text-zinc-500">{{ __("Used when a workflow step assigns the requester's manager.") }}</p>
                </div>

                <div class="sm:col-span-2">
                    @php($editingSelf = $editingUserId && Auth::id() === $editingUserId && ! Auth::user()?->isSystemOwner())
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
                @if ($editingUserId && Auth::user()?->can('anonymize', User::query()->find($editingUserId)))
                    <flux:button type="button" variant="danger" wire:click="anonymizeUser({{ $editingUserId }})" wire:confirm="{{ __('Remove this person’s name and email? Decisions they recorded stay on the requests.') }}" data-test="anonymize-user">
                        {{ __('Anonymize account') }}
                    </flux:button>
                @endif
                <flux:button type="submit" variant="primary" data-test="save-user">{{ __('Save user') }}</flux:button>
            </div>
        </form>
        @if ($editingUserId && Auth::user()?->can('recover', User::query()->find($editingUserId)))
            <div class="mt-6 space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="account-recovery">
                <flux:heading size="sm">{{ __('Account recovery') }}</flux:heading>
                <flux:text>{{ __('These actions sign the person out everywhere. They do not change roles or request history.') }}</flux:text>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.users.recovery.password', $editingUserId) }}">
                        @csrf
                        <flux:button type="submit" variant="danger" data-test="recover-password" onclick="return confirm({{ json_encode(__('Invalidate this password and email a reset link?')) }})">
                            {{ __('Invalidate password and email reset link') }}
                        </flux:button>
                    </form>
                    <form method="POST" action="{{ route('admin.users.recovery.second-factor', $editingUserId) }}">
                        @csrf
                        <flux:button type="submit" variant="danger" data-test="recover-second-factor" onclick="return confirm({{ json_encode(__('Remove the authenticator, recovery codes, and passkeys?')) }})">
                            {{ __('Remove authenticator, recovery codes, and passkeys') }}
                        </flux:button>
                    </form>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
