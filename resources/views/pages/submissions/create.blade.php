<?php

use App\Actions\Submissions\AttachSubmissionFilesAction;
use App\Actions\Submissions\CreateSubmissionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Actions\Submissions\SyncLegacySubmissionFieldValuesAction;
use App\DTOs\SubmissionData;
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
 * Drafts auto-save every 30 seconds (text fields only; uploads persist on
 * explicit save/submit so files are never duplicated).
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

    public string $autosaveState = 'idle';

    public ?string $lastSavedAt = null;

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

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'title', 'body', 'priority', 'due_date'], true)) {
            $this->autosaveState = 'idle';
        }
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

    /** Silent textual autosave — never touches uploads. */
    public function autosave(): void
    {
        if (trim($this->title) === '') {
            return;
        }

        $this->autosaveState = 'saving';
        $this->resetErrorBag('autosave');

        try {
            $this->validateOnly('title');
            $this->validateOnly('type');
            $this->persistFields();
        } catch (\Illuminate\Validation\ValidationException) {
            $this->autosaveState = 'failed';
            $this->addError('autosave', 'Autosave failed. Fix the highlighted fields and retry.');

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->autosaveState = 'failed';
            $this->addError('autosave', 'Autosave failed. Check your connection and retry.');

            return;
        }

        $this->lastSavedAt = now()->format('H:i:s');
        $this->autosaveState = 'saved';
    }

    public function saveDraft(): void
    {
        $this->validateOnly('title');
        $this->validateOnly('type');

        $this->persistFields();
        $this->persistAttachments();

        Flux::toast('Draft saved.', variant: 'success');

        $this->redirect(route('submissions.index'), navigate: true);
    }

    public function submit(): void
    {
        $this->validate();

        $submission = DB::transaction(function (): Submission {
            $submission = $this->persistFields();
            $this->persistAttachments();

            return app(SubmitSubmissionAction::class)->execute($submission);
        });

        Flux::toast('Submission sent for approval.', variant: 'success');

        $this->redirect(route('submissions.show', $submission), navigate: true);
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
            $this->authorize('update', $file->submission);
            \Illuminate\Support\Facades\Storage::disk($file->disk)->delete($file->stored_path);
            $file->delete();
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

<div wire:poll.30s="autosave">
    <x-ui.breadcrumbs :items="[['label' => __('Submissions'), 'url' => route('submissions.index')], ['label' => $draftId ? __('Edit draft') : __('New submission')]]" :back="$draftId ? route('submissions.show', $draftId) : route('submissions.index')" back-label="Back to submissions" class="mb-6" />

    <x-ui.page-header
        :title="$draftId ? __('Edit draft') : __('New submission')"
        :description="__('Drafts auto-save every 30 seconds while you type.')"
    />

    <div class="mt-3 flex min-h-11 flex-wrap items-center gap-2 text-sm" aria-live="polite" aria-atomic="true" data-test="autosave-status">
        <span wire:offline class="inline-flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 font-medium text-red-700 dark:bg-red-950/30 dark:text-red-300">
            <span aria-hidden="true">●</span> {{ __('Offline — changes are not being saved') }}
        </span>
        <div wire:offline.remove>
            <span wire:loading wire:target="autosave" class="inline-flex items-center gap-2 text-blue-700 dark:text-blue-300">
                <span class="size-2 animate-pulse rounded-full bg-blue-600" aria-hidden="true"></span> {{ __('Saving…') }}
            </span>
            <span wire:loading.remove wire:target="autosave">
                @if ($autosaveState === 'saved' && $lastSavedAt)
                    <span class="inline-flex items-center gap-2 text-green-700 dark:text-green-300">
                        <span aria-hidden="true">✓</span> {{ __('Saved at :time', ['time' => $lastSavedAt]) }}
                    </span>
                @elseif ($autosaveState === 'failed')
                    <span class="inline-flex flex-wrap items-center gap-2 text-red-700 dark:text-red-300">
                        <span>{{ __('Autosave failed.') }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="autosave">{{ __('Retry') }}</flux:button>
                    </span>
                @else
                    <span class="text-zinc-600 dark:text-zinc-300">{{ __('Changes autosave every 30 seconds.') }}</span>
                @endif
            </span>
        </div>
        <flux:error name="autosave" />
    </div>

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
                <flux:input type="file" wire:model="attachments" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt" :label="__('Attachments')" />
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
                                <flux:button size="xs" variant="ghost" wire:click="removeSavedFile({{ $saved->id }})" wire:confirm="{{ __('Remove this file?') }}">
                                    {{ __('Remove') }}
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
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
