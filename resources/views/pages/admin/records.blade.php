<?php

use App\Actions\Audit\GrantAuditorAccessAction;
use App\Actions\Audit\PlaceLegalHoldAction;
use App\Actions\Audit\ReleaseLegalHoldAction;
use App\Actions\Audit\RequestDispositionAction;
use App\Actions\Audit\ReviewDispositionAction;
use App\Actions\Audit\RevokeAuditorAccessAction;
use App\Enums\DispositionStatus;
use App\Models\AuditorAccess;
use App\Models\DispositionReview;
use App\Models\LegalHold;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Services\Audit\EvidencePackVerifier;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Records')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public int $auditorUserId = 0;

    public string $accessStartsAt = '';

    public string $accessEndsAt = '';

    public string $accessReason = '';

    public string $holdReference = '';

    public string $holdReason = '';

    public string $dispositionReference = '';

    public string $dispositionNotes = '';

    public string $draftDays = '90';

    public string $completedDays = '2555';

    public $verifyFile = null;

    public ?string $verifyResult = null;

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->draftDays = (string) Setting::get('retention.draft_days', '90');
        $this->completedDays = (string) Setting::get('retention.completed_days', '2555');
        $this->accessStartsAt = now()->format('Y-m-d H:i');
        $this->accessEndsAt = now()->addMonth()->format('Y-m-d H:i');
    }

    #[Computed]
    public function grants(): \Illuminate\Database\Eloquent\Collection
    {
        return AuditorAccess::query()->with(['user:id,name,email', 'grantor:id,name'])->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function holds(): \Illuminate\Database\Eloquent\Collection
    {
        return LegalHold::query()->with(['submission:id,reference_number,title', 'placer:id,name'])->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function reviews(): \Illuminate\Database\Eloquent\Collection
    {
        return DispositionReview::query()->with('submission:id,reference_number,title')->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function auditors(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->role('auditor')->orderBy('name')->get(['id', 'name', 'email']);
    }

    public function grantAccess(GrantAuditorAccessAction $grant): void
    {
        $this->authorize('settings.manage');
        $this->validate([
            'auditorUserId' => ['required', 'integer', 'exists:users,id'],
            'accessStartsAt' => ['required', 'date'],
            'accessEndsAt' => ['required', 'date', 'after:accessStartsAt'],
            'accessReason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $grant->execute(
                Auth::user(),
                User::query()->findOrFail($this->auditorUserId),
                Carbon::parse($this->accessStartsAt),
                Carbon::parse($this->accessEndsAt),
                $this->accessReason,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['accessReason' => $exception->getMessage()]);
        }

        unset($this->grants);
        Flux::toast('Auditor access granted.', variant: 'success');
    }

    public function revokeAccess(int $accessId, RevokeAuditorAccessAction $revoke): void
    {
        $this->authorize('settings.manage');
        $revoke->execute(Auth::user(), AuditorAccess::query()->findOrFail($accessId));
        unset($this->grants);
        Flux::toast('Auditor access revoked.', variant: 'success');
    }

    public function saveRetention(): void
    {
        $this->authorize('settings.manage');
        $this->validate([
            'draftDays' => ['required', 'integer', 'min:1', 'max:3650'],
            'completedDays' => ['required', 'integer', 'min:1', 'max:7300'],
        ]);
        Setting::put('retention.draft_days', (string) (int) $this->draftDays);
        Setting::put('retention.completed_days', (string) (int) $this->completedDays);
        Flux::toast('Retention saved.', variant: 'success');
    }

    public function placeHold(PlaceLegalHoldAction $place): void
    {
        $this->authorize('settings.manage');
        $this->validate([
            'holdReference' => ['required', 'string'],
            'holdReason' => ['required', 'string', 'max:255'],
        ]);
        $submission = Submission::query()->where('reference_number', $this->holdReference)->first();
        if ($submission === null) {
            throw ValidationException::withMessages(['holdReference' => 'No request has that reference.']);
        }
        try {
            $place->execute(Auth::user(), $submission, $this->holdReason);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['holdReason' => $exception->getMessage()]);
        }
        unset($this->holds);
        Flux::toast('Legal hold placed.', variant: 'success');
    }

    public function releaseHold(int $holdId, ReleaseLegalHoldAction $release): void
    {
        $this->authorize('settings.manage');
        $release->execute(Auth::user(), LegalHold::query()->findOrFail($holdId));
        unset($this->holds);
        Flux::toast('Legal hold released.', variant: 'success');
    }

    public function requestDisposition(RequestDispositionAction $request): void
    {
        $this->authorize('settings.manage');
        $this->validate([
            'dispositionReference' => ['required', 'string'],
        ]);
        $submission = Submission::query()->where('reference_number', $this->dispositionReference)->first();
        if ($submission === null) {
            throw ValidationException::withMessages(['dispositionReference' => 'No request has that reference.']);
        }
        $request->execute(Auth::user(), $submission, $this->dispositionNotes);
        unset($this->reviews);
        Flux::toast('Disposition review opened.', variant: 'success');
    }

    public function approveDisposition(int $reviewId, ReviewDispositionAction $review): void
    {
        $this->authorize('settings.manage');
        $review->execute(Auth::user(), DispositionReview::query()->findOrFail($reviewId), DispositionStatus::Approved);
        unset($this->reviews);
        Flux::toast('Disposition approved.', variant: 'success');
    }

    public function verifyPack(EvidencePackVerifier $verifier): void
    {
        $this->authorize('settings.manage');
        $this->validate(['verifyFile' => ['required', 'file', 'max:20480']]);
        $path = $this->verifyFile->getRealPath();
        $result = $verifier->verifyPath((string) $path);
        $this->verifyResult = $result->ok ? 'This pack is intact.' : $result->error;
    }
} ?>

<div>
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Records')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header :title="__('Records')" :description="__('Evidence packs, auditor access, legal holds, and retention.')" />

    <div class="mt-8 grid gap-8 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="sm">{{ __('Auditor access') }}</flux:heading>
            <div class="mt-4 space-y-3">
                <flux:select wire:model="auditorUserId" :label="__('Auditor')">
                    <flux:select.option value="0">{{ __('Choose an auditor') }}</flux:select.option>
                    @foreach ($this->auditors as $auditor)
                        <flux:select.option value="{{ $auditor->id }}">{{ $auditor->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="datetime-local" wire:model="accessStartsAt" :label="__('Starts')"/>
                <flux:input type="datetime-local" wire:model="accessEndsAt" :label="__('Ends')"/>
                <flux:input wire:model="accessReason" :label="__('Reason')"/>
                <flux:error name="accessReason" />
                <flux:button variant="primary" wire:click="grantAccess">{{ __('Grant access') }}</flux:button>
            </div>
            <ul class="mt-4 space-y-2 text-sm">
                @forelse ($this->grants as $grant)
                    <li class="flex items-start justify-between gap-3">
                        <span>
                            {{ $grant->user?->name }} · {{ $grant->starts_at->format('d M Y') }}–{{ $grant->ends_at->format('d M Y') }}
                            @if ($grant->revoked_at)
                                · {{ __('revoked') }}
                            @endif
                        </span>
                        @if ($grant->revoked_at === null)
                            <flux:button size="xs" variant="ghost" wire:click="revokeAccess({{ $grant->id }})">{{ __('Revoke') }}</flux:button>
                        @endif
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No grants yet.') }}</li>
                @endforelse
            </ul>
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Retention') }}</flux:heading>
            <div class="mt-4 space-y-3">
                <flux:input type="number" wire:model="draftDays" :label="__('Drafts older than (days)')"/>
                <flux:input type="number" wire:model="completedDays" :label="__('Finished requests older than (days)')"/>
                <flux:button variant="primary" wire:click="saveRetention">{{ __('Save retention') }}</flux:button>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Legal holds') }}</flux:heading>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="holdReference" :label="__('Request reference')"/>
                <flux:error name="holdReference" />
                <flux:input wire:model="holdReason" :label="__('Reason')"/>
                <flux:button variant="primary" wire:click="placeHold">{{ __('Place hold') }}</flux:button>
            </div>
            <ul class="mt-4 space-y-2 text-sm">
                @forelse ($this->holds as $hold)
                    <li class="flex items-start justify-between gap-3">
                        <span>{{ $hold->submission?->reference_number }} · {{ $hold->reason }} @if ($hold->released_at) · {{ __('released') }} @endif</span>
                        @if ($hold->released_at === null)
                            <flux:button size="xs" variant="ghost" wire:click="releaseHold({{ $hold->id }})">{{ __('Release') }}</flux:button>
                        @endif
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No legal holds.') }}</li>
                @endforelse
            </ul>
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Disposition') }}</flux:heading>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="dispositionReference" :label="__('Request reference')"/>
                <flux:error name="dispositionReference" />
                <flux:input wire:model="dispositionNotes" :label="__('Notes')"/>
                <flux:button variant="primary" wire:click="requestDisposition">{{ __('Open review') }}</flux:button>
            </div>
            <ul class="mt-4 space-y-2 text-sm">
                @forelse ($this->reviews as $review)
                    <li class="flex items-start justify-between gap-3">
                        <span>{{ $review->submission?->reference_number }} · {{ $review->status->value }}</span>
                        @if ($review->status === DispositionStatus::Pending)
                            <flux:button size="xs" variant="ghost" wire:click="approveDisposition({{ $review->id }})">{{ __('Approve') }}</flux:button>
                        @endif
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No disposition reviews.') }}</li>
                @endforelse
            </ul>
        </flux:card>

        <flux:card class="lg:col-span-2">
            <flux:heading size="sm">{{ __('Verify an evidence pack') }}</flux:heading>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <flux:input type="file" wire:model="verifyFile" :label="__('Pack file')"/>
                <flux:button wire:click="verifyPack">{{ __('Verify') }}</flux:button>
            </div>
            @if ($verifyResult)
                <p class="mt-3 text-sm" data-test="verify-result">{{ $verifyResult }}</p>
            @endif
        </flux:card>
    </div>
</div>
