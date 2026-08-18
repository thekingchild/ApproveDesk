<?php

use App\Actions\Departments\CreateDepartmentAction;
use App\Actions\Departments\UpdateDepartmentAction;
use App\DTOs\DepartmentData;
use App\Models\Department;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Department management')] class extends Component {
    use WithPagination;

    public string $search = '';

    public ?int $editingDepartmentId = null;

    public string $name = '';

    public string $parentId = '';

    public string $managerId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);
    }

    #[Computed]
    public function departments(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Department::query()
            ->with(['parent', 'manager'])
            ->withCount(['users', 'children'])
            ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function parentOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()
            ->when($this->editingDepartmentId !== null, fn (Builder $query) => $query->whereKeyNot($this->editingDepartmentId))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function managerOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createDepartment(): void
    {
        $this->authorize('create', Department::class);
        $this->resetForm();
        $this->modal('department-form')->show();
    }

    public function editDepartment(int $departmentId): void
    {
        $department = Department::query()->findOrFail($departmentId);
        $this->authorize('update', $department);

        $this->resetValidation();
        $this->editingDepartmentId = $department->id;
        $this->name = $department->name;
        $this->parentId = $department->parent_id ? (string) $department->parent_id : '';
        $this->managerId = $department->manager_id ? (string) $department->manager_id : '';
        unset($this->parentOptions);

        $this->modal('department-form')->show();
    }

    public function save(): void
    {
        $target = $this->editingDepartmentId
            ? Department::query()->findOrFail($this->editingDepartmentId)
            : null;

        $this->authorize($target ? 'update' : 'create', $target ?? Department::class);

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique(Department::class, 'name')->ignore($target),
            ],
            'parentId' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'managerId' => [
                'nullable', 'integer',
                Rule::exists(User::class, 'id')->where('is_active', true),
            ],
        ]);

        $data = new DepartmentData(
            name: $validated['name'],
            parentId: $validated['parentId'] === '' ? null : (int) $validated['parentId'],
            managerId: $validated['managerId'] === '' ? null : (int) $validated['managerId'],
        );

        if ($target) {
            app(UpdateDepartmentAction::class)->execute($target, $data);
            $message = 'Department updated.';
        } else {
            app(CreateDepartmentAction::class)->execute($data);
            $message = 'Department created.';
        }

        $this->resetForm();
        unset($this->departments, $this->parentOptions);
        $this->modal('department-form')->close();
        Flux::toast($message, variant: 'success');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingDepartmentId', 'name', 'parentId', 'managerId']);
        unset($this->parentOptions);
    }
} ?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Departments')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('Departments') }}</flux:heading>
            <flux:subheading>{{ __('Organize teams, reporting lines, and department managers.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="createDepartment" data-test="create-department">
            {{ __('Add department') }}
        </flux:button>
    </div>

    <div class="max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search departments…')" />
    </div>

    <flux:table :paginate="$this->departments">
        <flux:table.columns>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Parent') }}</flux:table.column>
            <flux:table.column>{{ __('Manager') }}</flux:table.column>
            <flux:table.column>{{ __('Members') }}</flux:table.column>
            <flux:table.column>{{ __('Subdepartments') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->departments as $department)
                <flux:table.row :key="$department->id">
                    <flux:table.cell class="font-medium">{{ $department->name }}</flux:table.cell>
                    <flux:table.cell>{{ $department->parent?->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $department->manager?->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell><flux:badge color="zinc">{{ $department->users_count }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge color="zinc">{{ $department->children_count }}</flux:badge></flux:table.cell>
                    <flux:table.cell class="text-right">
                        <flux:button size="xs" variant="ghost" wire:click="editDepartment({{ $department->id }})">{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No departments found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="department-form" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="md">{{ $editingDepartmentId ? __('Edit department') : __('Add department') }}</flux:heading>
                <flux:subheading>{{ __('Set an optional parent and manager for this department.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" required />

            <flux:select wire:model="parentId" :label="__('Parent department')">
                <flux:select.option value="">{{ __('No parent') }}</flux:select.option>
                @foreach ($this->parentOptions as $department)
                    <flux:select.option :value="$department->id">{{ $department->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="parentId" />

            <flux:select wire:model="managerId" :label="__('Manager')">
                <flux:select.option value="">{{ __('No manager') }}</flux:select.option>
                @foreach ($this->managerOptions as $user)
                    <flux:select.option :value="$user->id">{{ $user->name }} — {{ $user->email }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-department">{{ __('Save department') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
