<?php

use App\Actions\Submissions\AttachSubmissionFilesAction;
use App\Actions\Submissions\CreateSubmissionAction;
use App\Actions\Submissions\RemoveSubmissionFileAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Actions\Submissions\SyncLegacySubmissionFieldValuesAction;
use App\DTOs\SubmissionData;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Three-step submission wizard: details -> attachments -> review & submit.
 * Text and files persist only on explicit Save draft or submit.
 */
new #[Title('New submission')] class extends Component {
    use WithFileUploads;

    public int $step = 1;

    #[Locked]
    public ?int $draftId = null;

    public string $type = 'memo';

    public string $title = '';

    public string $body = '';

    public string $priority = 'normal';

    public ?string $due_date = null;

    public bool $offlineDraftConflict = false;

    /** @var array<string, string> */
    public array $pendingOfflineDraft = [];

    /** @var array<int, \Livewire\TemporaryUploadedFile> */
    public array $attachments = [];

    public function mount(?Submission $submission = null): void
    {
        if ($submission === null) {
            return;
        }

        $this->authorize('update', $submission);

        $this->draftId = $submission->id;
        $this->type = $submission->type->value;
        $this->title = $submission->title;
        $this->body = $submission->body ?? '';
        $this->priority = $submission->priority->value;
        $this->due_date = $submission->due_date?->format('Y-m-d');
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', 'in:document,note,memo,request'],
            'title' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:65535'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'due_date' => ['nullable', 'date', 'after:today'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,csv,txt'],
        ];
    }

    #[Computed]
    public function savedFiles(): object
    {
        return $this->draftId
            ? SubmissionFile::query()->where('submission_id', $this->draftId)->get()
            : collect();
    }

    public function updatedAttachments(): void
    {
        $this->validateOnly('attachments.*');
    }

    public function next(): void
    {
        $this->validateOnly($this->step === 1 ? 'title' : 'attachments.*');
        $this->step++;
    }

    public function previous(): void
    {
        $this->step--;
    }

    /**
     * Compare a browser-cached offline draft with the server fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reviewOfflineDraft(array $payload): void
    {
        $fields = ['title', 'body', 'type', 'priority', 'due_date'];
        $normalized = [];

        foreach ($fields as $field) {
            $normalized[$field] = isset($payload[$field]) ? (string) $payload[$field] : '';
        }

        $differs = false;
        foreach ($fields as $field) {
            if ($normalized[$field] !== (string) ($this->{$field} ?? '')) {
                $differs = true;
                break;
            }
        }

        if (! $differs) {
            $this->offlineDraftConflict = false;
            $this->pendingOfflineDraft = [];

            return;
        }

        $this->pendingOfflineDraft = $normalized;
        $this->offlineDraftConflict = true;
    }

    public function keepOfflineDraft(): void
    {
        if ($this->pendingOfflineDraft === []) {
            return;
        }

        foreach (['title', 'body', 'type', 'priority', 'due_date'] as $field) {
            if (array_key_exists($field, $this->pendingOfflineDraft)) {
                $value = $this->pendingOfflineDraft[$field];
                $this->{$field} = $field === 'due_date' && $value === '' ? null : $value;
            }
        }

        $this->pendingOfflineDraft = [];
        $this->offlineDraftConflict = false;
    }

    public function keepServerDraft(): void
    {
        $this->pendingOfflineDraft = [];
        $this->offlineDraftConflict = false;
    }

    public function saveDraft(): void
    {
        $this->validateOnly('title');
        $this->validateOnly('type');

        $this->saveDraftAtomically();

        Flux::toast('Draft saved.', variant: 'success');

        $this->redirect(route('submissions.index'), navigate: true);
    }

    public function submit(): void
    {
        $this->validate();

        // Save first so a refused submit never loses the requester's work (RV-BUG-07).
        $submission = $this->saveDraftAtomically();

        try {
            app(SubmitSubmissionAction::class)->execute($submission);
        } catch (WorkflowException $exception) {
            Flux::toast(__('Saved as a draft but not sent for approval: :reason', ['reason' => $exception->getMessage()]), variant: 'danger');
            $this->redirect(route('submissions.show', $submission), navigate: true);

            return;
        }

        Flux::toast('Submission sent for approval.', variant: 'success');

        $this->redirect(route('submissions.show', $submission), navigate: true);
    }

    private function saveDraftAtomically(): Submission
    {
        [$draftId, $attachments] = [$this->draftId, $this->attachments];

        try {
            return DB::transaction(function (): Submission {
                $submission = $this->persistFields();
                $this->persistAttachments();

                return $submission;
            });
        } catch (\Throwable $exception) {
            // A rolled-back insert must not leave the component pointing at a draft that does not exist.
            [$this->draftId, $this->attachments] = [$draftId, $attachments];

            throw $exception;
        }
    }

    public function removeSavedFile(int $fileId): void
    {
        if (! $this->draftId) {
            return;
        }

        $file = SubmissionFile::query()
            ->where('submission_id', $this->draftId)
            ->whereKey($fileId)
            ->first();

        if ($file) {
            app(RemoveSubmissionFileAction::class)->execute($file->submission, $file, Auth::user());
        }

        unset($this->savedFiles);
    }

    private function persistFields(): Submission
    {
        $data = SubmissionData::fromValidated([
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'priority' => $this->priority,
            'due_date' => $this->due_date ?: null,
        ]);

        if ($this->draftId) {
            $submission = Submission::query()->whereKey($this->draftId)->firstOrFail();
            $this->authorize('update', $submission);
            $submission->update($data->toAttributes());
            app(SyncLegacySubmissionFieldValuesAction::class)->execute($submission);

            return $submission;
        }

        $submission = app(CreateSubmissionAction::class)->execute($data, Auth::user(), asDraft: true);
        $this->draftId = $submission->id;

        return $submission;
    }

    private function persistAttachments(): void
    {
        if ($this->attachments === []) {
            return;
        }

        $submission = Submission::query()->whereKey($this->draftId)->firstOrFail();

        app(AttachSubmissionFilesAction::class)->execute($submission, Auth::user(), $this->attachments);

        $this->attachments = [];
        unset($this->savedFiles);
    }
} ?>

<div data-offline-draft="submission-draft-{{ $draftId ?? 'new' }}">
    <x-ui.breadcrumbs :items="[['label' => __('Submissions'), 'url' => route('submissions.index')], ['label' => $draftId ? __('Edit draft') : __('New submission')]]" :back="$draftId ? route('submissions.show', $draftId) : route('submissions.index')" back-label="Back to submissions" class="mb-6" />

    <x-ui.page-header
        :title="$draftId ? __('Edit draft') : __('New submission')"
        :description="__('Use Save draft to keep this request without submitting it.')"
    />

    <div class="mt-3 flex min-h-11 flex-wrap items-center gap-2 text-sm" aria-live="polite" aria-atomic="true">
        <span wire:offline class="inline-flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 font-medium text-red-700 dark:bg-red-950/30 dark:text-red-300">
            <span aria-hidden="true">●</span> {{ __('Offline — changes are not being saved') }}
        </span>
    </div>

    @if ($offlineDraftConflict)
        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100" data-test="offline-draft-conflict">
            <p>{{ __('This device has unsaved offline edits that differ from the saved draft.') }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:button size="xs" variant="primary" wire:click="keepOfflineDraft" data-test="keep-offline-draft">{{ __('Keep this device') }}</flux:button>
                <flux:button size="xs" variant="ghost" wire:click="keepServerDraft" data-test="keep-server-draft">{{ __('Keep saved draft') }}</flux:button>
            </div>
        </div>
    @endif

    <div class="mt-3 flex items-center gap-2">
        @foreach ([1 => __('Details'), 2 => __('Attachments'), 3 => __('Review')] as $n => $label)
            <flux:badge :color="$step > $n ? 'lime' : ($step === $n ? 'indigo' : 'zinc')">{{ $n }}. {{ $label }}</flux:badge>
        @endforeach
    </div>

    <form wire:submit="submit" class="mt-6 space-y-6">
        @csrf

        <x-ui.validation-summary />

        @if ($step === 1)
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="type" :label="__('Type')" required>
                    <flux:select.option value="document">{{ __('Document') }}</flux:select.option>
                    <flux:select.option value="note">{{ __('Note') }}</flux:select.option>
                    <flux:select.option value="memo">{{ __('Memo') }}</flux:select.option>
                    <flux:select.option value="request">{{ __('Request') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model="priority" :label="__('Priority')">
                    <flux:select.option value="low">{{ __('Low') }}</flux:select.option>
                    <flux:select.option value="normal">{{ __('Normal') }}</flux:select.option>
                    <flux:select.option value="high">{{ __('High') }}</flux:select.option>
                    <flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option>
                </flux:select>

                <div class="sm:col-span-2">
                    <flux:input wire:model="title" :label="__('Title')" placeholder="{{ __('What is this about?') }}" required maxlength="500" dusk="request-title" />
                    <flux:error name="title" />
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-2 block text-sm font-medium">{{ __('Body') }}</label>
                    <div
                        x-data="richTextEditor(@js($body))"
                        x-modelable="content"
                        wire:model="body"
                        class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <div class="flex flex-wrap gap-1 border-b border-zinc-200 p-2 dark:border-zinc-700">
                            @foreach (['paragraph' => '¶', 'heading2' => 'H2', 'heading3' => 'H3', 'bold' => 'B', 'italic' => 'I', 'strike' => 'S', 'bulletList' => '• List', 'orderedList' => '1. List', 'blockquote' => 'Quote', 'codeBlock' => 'Code', 'undo' => '↶', 'redo' => '↷'] as $command => $label)
                                <button type="button" class="rounded px-2 py-1 text-xs font-medium hover:bg-zinc-100 dark:hover:bg-zinc-800" x-on:click="command('{{ $command }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                        <div wire:ignore x-ref="editor"></div>
                    </div>
                    <flux:error name="body" />
                </div>

                <flux:input wire:model="due_date" type="date" :label="__('Due date (optional)')" />
            </div>
        @elseif ($step === 2)
            <div>
                <flux:input type="file" wire:model="attachments" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,image/*" :label="__('Attachments')" />
                <p class="mt-1 text-xs text-zinc-500">{{ __('On a phone you can take a photo or choose a file. Approval decisions wait until you are online.') }}</p>
                <flux:error name="attachments.*" />

                @if (count($attachments))
                    <ul class="mt-3 space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                        @foreach ($attachments as $file)
                            <li>📎 {{ $file->getClientOriginalName() }} ({{ number_format($file->getSize() / 1024) }} KB)</li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->savedFiles->isNotEmpty())
                    <flux:separator class="my-4" />
                    <p class="mb-2 text-sm font-medium">{{ __('Saved files') }}</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($this->savedFiles as $saved)
                            <li class="flex items-center justify-between gap-3">
                                <span>📎 {{ $saved->original_name }}</span>
                                <flux:button size="xs" variant="ghost" wire:click="removeSavedFile({{ $saved->id }})" wire:confirm="{{ __('Remove this file? If the request was already submitted, the file stays in its history for approvers and auditors.') }}">
                                    {{ __('Remove') }}
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <flux:error name="attachments" />
            </div>
        @else
            <flux:card>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs text-zinc-500">{{ __('Type') }}</dt><dd class="font-medium">{{ ucfirst($type) }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('Priority') }}</dt><dd class="font-medium">{{ ucfirst($priority) }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">{{ __('Title') }}</dt><dd class="font-medium">{{ $title }}</dd></div>
                    @if ($due_date)<div><dt class="text-xs text-zinc-500">{{ __('Due date') }}</dt><dd class="font-medium">{{ $due_date }}</dd></div>@endif
                    <div><dt class="text-xs text-zinc-500">{{ __('Files') }}</dt>
                        <dd class="font-medium">{{ count($attachments) + $this->savedFiles->count() }}</dd></div>
                    @if ($body)<div class="sm:col-span-2"><dt class="text-xs text-zinc-500">{{ __('Body') }}</dt><dd class="prose prose-sm dark:prose-invert max-w-none">{!! app(\App\Services\RichTextSanitizer::class)->sanitize($body) !!}</dd></div>@endif
                </dl>
            </flux:card>
        @endif

        <div class="flex items-center justify-between">
            <div>
                @if ($step > 1)
                    <flux:button type="button" variant="ghost" wire:click="previous">{{ __('Back') }}</flux:button>
                @endif
            </div>

            <div class="flex items-center gap-3">
                @if ($step < 3)
                    <flux:button type="button" variant="ghost" wire:click="saveDraft" dusk="save-draft">{{ __('Save draft') }}</flux:button>
                    <flux:button type="button" variant="primary" wire:click="next" dusk="continue">{{ __('Continue') }}</flux:button>
                @else
                    <flux:button type="button" variant="ghost" wire:click="saveDraft">{{ __('Save draft') }}</flux:button>
                    <flux:button type="submit" variant="primary" data-test="submit-submission" dusk="submit-request">{{ __('Submit for approval') }}</flux:button>
                @endif
            </div>
        </div>
    </form>
</div>
