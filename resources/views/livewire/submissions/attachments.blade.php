<?php

use App\Support\SubmissionFileLinks;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use App\Models\Submission;
use Livewire\Component;

/*
 * Attachments card and preview dialog of the request page (ADR 0006 split, RV-ARC-02).
 * Links are signed by SubmissionFileLinks; the download controller re-checks access.
 */
new class extends Component {
    public Submission $submission;

    #[Locked]
    public ?int $previewFileId = null;

    public function hydrate(): void
    {
        $this->submission->loadMissing(['files', 'removedFiles.remover:id,name']);
    }

    public function mount(Submission $submission): void
    {
        $this->authorize('view', $submission);
        $this->submission = $submission->loadMissing(['files', 'removedFiles.remover:id,name']);
    }

    #[Computed]
    public function previewFile(): ?\App\Models\SubmissionFile
    {
        return $this->submission->files->firstWhere('id', $this->previewFileId);
    }

    public function openPreview(int $fileId): void
    {
        $file = $this->submission->files->firstWhere('id', $fileId);
        abort_unless($file?->isPreviewable(), 404);
        $this->authorize('downloadFiles', $this->submission);

        $this->previewFileId = $file->id;
        unset($this->previewFile);
        $this->modal('file-preview')->show();
    }

    protected function fileUrl(\App\Models\SubmissionFile $file): string
    {
        return SubmissionFileLinks::download($file);
    }

    protected function previewUrl(\App\Models\SubmissionFile $file): string
    {
        return SubmissionFileLinks::preview($file);
    }
}; ?>

<div>
    @if ($submission->files->isNotEmpty() || $submission->removedFiles->isNotEmpty())
        <flux:card>
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>
            @if ($submission->files->isEmpty())
                <flux:text class="mt-3 text-sm">{{ __('No current attachments.') }}</flux:text>
            @endif
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($submission->files as $file)
                    <li class="flex items-center justify-between gap-3">
                        <span>📎 {{ $file->original_name }} <span class="text-xs text-zinc-400">({{ number_format($file->size_bytes / 1024) }} KB)</span></span>
                        <span class="flex items-center gap-3">
                            @if ($file->isPreviewable())
                                <flux:button size="xs" variant="ghost" wire:click="openPreview({{ $file->id }})">{{ __('Preview') }}</flux:button>
                            @endif
                            <flux:link :href="$this->fileUrl($file)" target="_blank">{{ __('Download') }}</flux:link>
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($submission->removedFiles->isNotEmpty())
                <flux:separator class="my-4" />
                <p class="text-sm font-medium">{{ __('Removed by the requester') }}</p>
                <p class="text-xs text-zinc-500">{{ __('Kept for the record because the request had already been submitted.') }}</p>
                <ul class="mt-2 space-y-2 text-sm" data-test="removed-attachments">
                    @foreach ($submission->removedFiles as $file)
                        <li class="flex items-center justify-between gap-3 text-zinc-500">
                            <span><span class="line-through">{{ $file->original_name }}</span> <span class="text-xs">· {{ __('removed by :name on :date', ['name' => $file->remover?->name ?? __('a former user'), 'date' => $file->deleted_at !== null ? \App\Support\LocalTime::forViewer($file->deleted_at) : '—']) }}</span></span>
                            @can('downloadFiles', $submission)
                                <flux:link :href="$this->fileUrl($file)" target="_blank">{{ __('Download') }}</flux:link>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>

        <flux:modal name="file-preview" class="w-full max-w-5xl">
            @if ($this->previewFile)
                <flux:heading size="md">{{ $this->previewFile->original_name }}</flux:heading>
                <div class="mt-4 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-900">
                    @if (str_starts_with($this->previewFile->mime_type, 'image/'))
                        <img src="{{ $this->previewUrl($this->previewFile) }}" alt="{{ $this->previewFile->original_name }}" class="mx-auto max-h-[70vh] object-contain" />
                    @else
                        <iframe src="{{ $this->previewUrl($this->previewFile) }}" title="{{ $this->previewFile->original_name }}" class="h-[70vh] w-full"></iframe>
                    @endif
                </div>
            @endif
        </flux:modal>
    @endif
</div>
