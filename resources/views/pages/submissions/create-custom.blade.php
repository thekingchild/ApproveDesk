<?php

use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Actions\Submissions\AttachSubmissionFilesAction;
use App\Actions\Submissions\CreateSubmissionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\DTOs\SubmissionData;
use App\Enums\RequestFieldType;
use App\Enums\SubmissionType;
use App\Models\Department;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\RequestTypes\RequestSchemaCompiler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Custom request')] class extends Component {
    use WithFileUploads;

    #[Locked] public int $requestTypeVersionId;
    #[Locked] public ?int $draftId = null;
    public int $step = 1;
    /** @var array<string, mixed> */ public array $values = [];
    /** @var array<string, array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>> */ public array $uploads = [];

    public function mount(?RequestType $requestType = null, ?Submission $submission = null): void
    {
        if ($submission !== null) {
            $this->authorize('update', $submission);
            abort_if($submission->requestTypeVersion->requestType->is_system, 404);
            $this->draftId = $submission->id;
            $this->requestTypeVersionId = $submission->request_type_version_id;
            $this->values = $submission->fieldValues()->with('field')->get()->mapWithKeys(fn ($value): array => [$value->field->key => $value->resolvedValue()])->all();
        } else {
            abort_if($requestType === null, 404);
            $this->authorize('use', $requestType);
            $this->requestTypeVersionId = $requestType->publishedVersion()->value('id');
        }

        $this->values = app(RequestSchemaCompiler::class)->defaults($this->version, $this->values);
    }

    #[Computed] public function version(): RequestTypeVersion
    {
        return RequestTypeVersion::query()->with(['requestType', 'fields'])->findOrFail($this->requestTypeVersionId);
    }

    #[Computed] public function users(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed] public function departments(): \Illuminate\Database\Eloquent\Collection
    {
        return Department::query()->orderBy('name')->get(['id', 'name']);
    }

    public function review(): void
    {
        $this->validateUploads();
        $this->values = $this->valuesForValidation();
        app(RequestSchemaCompiler::class)->validate($this->version, $this->values);
        $this->values = app(RequestSchemaCompiler::class)->withCalculatedValues($this->version, $this->values);
        $this->step = 2;
    }

    public function saveDraft(): void
    {
        $submission = $this->persist(false);
        Flux::toast('Draft saved.', variant: 'success');
        $this->redirect(route('submissions.show', $submission), navigate: true);
    }

    public function submit(): void
    {
        $submission = $this->persist(true);
        app(SubmitSubmissionAction::class)->execute($submission);
        Flux::toast('Submission sent for approval.', variant: 'success');
        $this->redirect(route('submissions.show', $submission), navigate: true);
    }

    private function persist(bool $enforceRequired): Submission
    {
        $this->validateUploads();
        $this->values = $this->valuesForValidation();
        $validated = app(RequestSchemaCompiler::class)->validate($this->version, $this->values, $enforceRequired);
        $title = collect($validated)->filter(fn ($value) => is_string($value) && trim($value) !== '')->first() ?: $this->version->requestType->name.' request';

        if ($this->draftId === null) {
            $submission = app(CreateSubmissionAction::class)->execute(new SubmissionData(
                type: SubmissionType::Request,
                title: str($title)->limit(500)->toString(),
                body: null,
                requestTypeVersionId: $this->version->id,
            ), Auth::user(), true);
            $this->draftId = $submission->id;
        } else {
            $submission = Submission::findOrFail($this->draftId);
            $this->authorize('update', $submission);
            $submission->update(['title' => str($title)->limit(500)->toString()]);
        }

        $allUploads = collect($this->uploads)->flatten()->all();
        if ($allUploads !== []) {
            app(AttachSubmissionFilesAction::class)->execute($submission, Auth::user(), $allUploads);
            foreach ($this->uploads as $key => $uploads) {
                $validated[$key] = collect($submission->files()->latest('id')->limit(count($uploads))->get())->pluck('id')->all();
            }
        }

        app(StoreSubmissionFieldValuesAction::class)->execute($submission, $validated, $enforceRequired);

        return $submission;
    }

    /** @return array<string, mixed> */
    private function valuesForValidation(): array
    {
        $values = $this->values;

        foreach ($this->uploads as $key => $uploads) {
            $values[$key] = collect($uploads)->map(fn ($upload): string => $upload->getClientOriginalName())->all();
        }

        return $values;
    }

    private function validateUploads(): void
    {
        foreach ($this->version->fields->where('type', RequestFieldType::File) as $field) {
            if (! isset($this->uploads[$field->key])) {
                continue;
            }

            $allowed = ($field->configuration ?? [])['allowed_types'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
            $this->validate([
                "uploads.{$field->key}.*" => ['file', 'max:10240', 'mimes:'.implode(',', $allowed)],
            ]);
        }
    }
};
?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Submissions'), 'url' => route('submissions.index')], ['label' => $this->version->requestType->name]]" :back="route('submissions.choose-type')" back-label="Choose another request type" />
    <x-ui.page-header :title="$this->version->requestType->name" :description="$this->version->requestType->description ?? __('Complete the fields below.')" />
    <div class="flex gap-2"><flux:badge :color="$step === 1 ? 'indigo' : 'lime'">1. {{ __('Details') }}</flux:badge><flux:badge :color="$step === 2 ? 'indigo' : 'zinc'">2. {{ __('Review') }}</flux:badge></div>

    @if ($step === 1)
        <form wire:submit="review" class="space-y-6">
            @php $currentSection = null; @endphp
            @foreach ($this->version->fields as $field)
                @continue(!app(RequestSchemaCompiler::class)->isVisible($field, $values))
                @php $config = $field->configuration ?? []; @endphp
                @if (($config['section'] ?? null) !== $currentSection)
                    @php $currentSection = $config['section'] ?? null; @endphp
                    @if ($currentSection)<div class="border-b border-zinc-200 pb-2 dark:border-zinc-700"><flux:heading size="md">{{ $currentSection }}</flux:heading>@if ($config['instructions'] ?? null)<flux:subheading>{{ $config['instructions'] }}</flux:subheading>@endif</div>@endif
                @endif
                <div class="space-y-1" wire:key="custom-field-{{ $field->id }}">
                    @if ($field->type === RequestFieldType::LongText || $field->type === RequestFieldType::RichText)
                        <flux:textarea wire:model.live="values.{{ $field->key }}" :label="$field->label" :placeholder="$config['placeholder'] ?? ''" :required="app(RequestSchemaCompiler::class)->isRequired($field, $values)" />
                    @elseif (in_array($field->type, [RequestFieldType::Select, RequestFieldType::User, RequestFieldType::Department], true))
                        <flux:select wire:model.live="values.{{ $field->key }}" :label="$field->label" :required="app(RequestSchemaCompiler::class)->isRequired($field, $values)"><flux:select.option value="">{{ __('Choose…') }}</flux:select.option>@foreach ($field->type === RequestFieldType::User ? $this->users : ($field->type === RequestFieldType::Department ? $this->departments : collect($config['options'] ?? [])) as $option)<flux:select.option :value="is_object($option) ? $option->id : $option">{{ is_object($option) ? $option->name : $option }}</flux:select.option>@endforeach</flux:select>
                    @elseif ($field->type === RequestFieldType::MultiSelect)
                        <label class="block text-sm font-medium">{{ $field->label }}</label><select wire:model.live="values.{{ $field->key }}" multiple class="min-h-28 w-full rounded-lg border bg-white p-2 dark:bg-zinc-900">@foreach ($config['options'] ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
                    @elseif ($field->type === RequestFieldType::Radio)
                        <fieldset><legend class="text-sm font-medium">{{ $field->label }}</legend><div class="mt-2 flex flex-wrap gap-4">@foreach ($config['options'] ?? [] as $option)<label><input type="radio" wire:model.live="values.{{ $field->key }}" value="{{ $option }}"> {{ $option }}</label>@endforeach</div></fieldset>
                    @elseif ($field->type === RequestFieldType::Checkbox)
                        <flux:checkbox wire:model.live="values.{{ $field->key }}" :label="$field->label" />
                    @elseif ($field->type === RequestFieldType::File)
                        <flux:input type="file" wire:model="uploads.{{ $field->key }}" multiple :label="$field->label" :accept="collect($config['allowed_types'] ?? [])->map(fn($type) => '.'.$type)->implode(',')" />
                    @elseif ($field->type === RequestFieldType::Calculated)
                        <flux:input :value="$values[$field->key] ?? 0" :label="$field->label" readonly />
                    @else
                        <flux:input wire:model.live="values.{{ $field->key }}" :type="match($field->type) { RequestFieldType::Number, RequestFieldType::Currency => 'number', RequestFieldType::Date => 'date', RequestFieldType::DateTime => 'datetime-local', RequestFieldType::Url => 'url', RequestFieldType::Email => 'email', default => 'text' }" :label="$field->label" :placeholder="$config['placeholder'] ?? ''" :required="app(RequestSchemaCompiler::class)->isRequired($field, $values)" />
                    @endif
                    @if ($config['help'] ?? null)<p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $config['help'] }}</p>@endif
                    <flux:error name="values.{{ $field->key }}" />
                </div>
            @endforeach
            <div class="flex justify-end gap-2"><flux:button type="button" wire:click="saveDraft">{{ __('Save draft') }}</flux:button><flux:button type="submit" variant="primary" dusk="review-custom-request">{{ __('Review request') }}</flux:button></div>
        </form>
    @else
        <flux:card><flux:heading size="md">{{ __('Review summary') }}</flux:heading><dl class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->version->fields as $field) @continue(!app(RequestSchemaCompiler::class)->isVisible($field, $values)) <div class="grid gap-1 py-3 sm:grid-cols-3"><dt class="font-medium">{{ $field->label }}</dt><dd class="sm:col-span-2">{{ is_array($values[$field->key] ?? null) ? implode(', ', $values[$field->key]) : (($values[$field->key] ?? '') !== '' ? $values[$field->key] : '—') }}</dd></div>@endforeach</dl></flux:card>
        <div class="flex justify-end gap-2"><flux:button wire:click="$set('step', 1)">{{ __('Back') }}</flux:button><flux:button wire:click="saveDraft">{{ __('Save draft') }}</flux:button><flux:button variant="primary" wire:click="submit" dusk="submit-custom-request">{{ __('Submit for approval') }}</flux:button></div>
    @endif
</div>
