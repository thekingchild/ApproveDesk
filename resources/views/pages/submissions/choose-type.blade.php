<?php

use App\Models\RequestType;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New request')] class extends Component {
    #[Computed]
    public function requestTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return RequestType::query()
            ->where('is_active', true)
            ->whereHas('publishedVersion')
            ->with('publishedVersion')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-6">
    <x-ui.breadcrumbs :items="[['label' => __('Submissions'), 'url' => route('submissions.index')], ['label' => __('New request')]]" :back="route('submissions.index')" back-label="Back to submissions" />
    <x-ui.page-header title="Choose a request type" description="Select the form that matches what you need approved." />
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->requestTypes as $type)
            <a href="{{ $type->is_system ? route('submissions.create') : route('submissions.custom.create', $type) }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:border-blue-500 dark:border-zinc-700 dark:bg-zinc-900" wire:navigate>
                <div class="font-semibold">{{ $type->name }}</div>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $type->description ?: __('Complete this request form.') }}</p>
                <div class="mt-3 text-xs text-zinc-500">{{ __('Version') }} {{ $type->publishedVersion->version }}</div>
            </a>
        @endforeach
    </div>
</div>
