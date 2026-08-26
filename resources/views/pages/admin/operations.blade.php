<?php

use App\Actions\Operations\ReplayDeadLetterAction;
use App\Actions\Operations\VerifyBackupAction;
use App\Models\OpsBackupVerification;
use App\Services\Operations\DeadLetterInbox;
use App\Services\Operations\OperationsMetrics;
use App\Services\Operations\OperationsReconciler;
use App\Services\Operations\StuckWorkflowDiagnostics;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Operations')] class extends Component
{
    public ?string $backupResult = null;

    public ?string $reconcileResult = null;

    public function mount(): void
    {
        $this->authorize('operations.view');
    }

    #[Computed]
    public function health(): \App\DTOs\Operations\OperationsHealth
    {
        return app(OperationsMetrics::class)->health();
    }

    #[Computed]
    public function deadLetters(): \Illuminate\Support\Collection
    {
        return app(DeadLetterInbox::class)->items();
    }

    #[Computed]
    public function stuck(): \Illuminate\Support\Collection
    {
        return app(StuckWorkflowDiagnostics::class)->find();
    }

    #[Computed]
    public function lastBackup(): ?OpsBackupVerification
    {
        return OpsBackupVerification::query()->latest('id')->first();
    }

    public function replay(string $type, string $id, ReplayDeadLetterAction $replay): void
    {
        $this->authorize('operations.replay');

        try {
            $replay->execute($type, $id);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['replay' => $exception->getMessage()]);
        }

        unset($this->deadLetters, $this->health);
        Flux::toast(__('Replay queued.'), variant: 'success');
    }

    public function verifyBackup(VerifyBackupAction $verify): void
    {
        $this->authorize('operations.verify_copy');
        $result = $verify->execute(Auth::user());
        $this->backupResult = $result->ok
            ? __('This copy checks out.')
            : __('This copy is missing required records.');
        unset($this->lastBackup);
    }

    public function reconcile(OperationsReconciler $reconciler): void
    {
        $this->authorize('operations.reconcile');
        $report = $reconciler->run();
        $this->reconcileResult = $report->ok()
            ? __('No inconsistencies found.')
            : implode(' ', $report->findings);
    }
};
?>

<div>
    <x-ui.breadcrumbs :items="[['label' => __('Dashboard'), 'url' => route('dashboard')], ['label' => __('Administration')], ['label' => __('Operations')]]" :back="route('dashboard')" back-label="Back to dashboard" />

    <x-ui.page-header :title="__('Operations')" :description="__('Health of scheduled work, failed deliveries, stuck requests, and backup checks.')" help-article="operations" />

    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <flux:heading size="sm">{{ __('Scheduler') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-heartbeat">
                @if ($this->health->schedulerFresh)
                    {{ __('Last run :time', ['time' => $this->health->schedulerHeartbeatAt?->diffForHumans()]) }}
                    <span class="block text-xs text-zinc-500">{{ $this->health->schedulerHeartbeatAt?->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}</span>
                @elseif ($this->health->schedulerHeartbeatAt)
                    {{ __('Stale — last run :time', ['time' => $this->health->schedulerHeartbeatAt->diffForHumans()]) }}
                @else
                    {{ __('No heartbeat yet') }}
                @endif
                <span class="mt-2 block text-xs text-zinc-500">{{ __('Check without running work: php artisan ops:scheduler-check') }}</span>
            </p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Waiting work') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-pending-jobs">{{ trans_choice(':count item waiting|:count items waiting', $this->health->pendingJobs, ['count' => $this->health->pendingJobs]) }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Failed queue jobs') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-failed-jobs">{{ $this->health->failedJobs }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Stuck requests') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-stuck">{{ $this->health->stuckWorkflows }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Webhook health') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-webhooks">{{ trans_choice(':count endpoint with a failed delivery|:count endpoints with a failed delivery', $this->health->failingWebhookEndpoints, ['count' => $this->health->failingWebhookEndpoints]) }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Connector health') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-connectors">{{ trans_choice(':count enabled connector with an error|:count enabled connectors with an error', $this->health->unhealthyConnectors, ['count' => $this->health->unhealthyConnectors]) }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Failed notices') }}</flux:heading>
            <p class="mt-2 text-sm">{{ $this->health->failedNotifications }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Failed integrations') }}</flux:heading>
            <p class="mt-2 text-sm">{{ $this->health->deadLetterIntegrations }}</p>
        </flux:card>
        <flux:card>
            <flux:heading size="sm">{{ __('Failed snapshots') }}</flux:heading>
            <p class="mt-2 text-sm" data-test="ops-failed-backups">
                <a class="underline" href="{{ route('admin.backups.index') }}" wire:navigate>{{ trans_choice(':count failed backup|:count failed backups', \App\Models\BackupSnapshot::query()->where('status', 'failed')->count(), ['count' => \App\Models\BackupSnapshot::query()->where('status', 'failed')->count()]) }}</a>
            </p>
        </flux:card>
    </div>

    <div class="mt-8 grid gap-8 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="sm">{{ __('Failed deliveries') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Replay sends the same item again. It does not create a second record or change the approval.') }}</p>
            <ul class="mt-4 space-y-3 text-sm">
                @forelse ($this->deadLetters as $item)
                    <li class="flex items-start justify-between gap-3" data-test="ops-dead-letter">
                        <span>
                            <span class="font-medium">{{ $item->label }}</span>
                            @if ($item->error)
                                <span class="block text-zinc-500">{{ $item->error }}</span>
                            @endif
                        </span>
                        <flux:button size="xs" variant="ghost" wire:click="replay('{{ $item->type }}', '{{ $item->id }}')">{{ __('Replay') }}</flux:button>
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('Nothing waiting to replay.') }}</li>
                @endforelse
            </ul>
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Stuck requests') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Active requests with no current step, or a step that has waited more than a day.') }}</p>
            <ul class="mt-4 space-y-3 text-sm">
                @forelse ($this->stuck as $item)
                    <li data-test="ops-stuck-row">
                        <a class="font-medium underline" href="{{ route('submissions.show', $item->submissionId) }}" wire:navigate>{{ $item->reference }}</a>
                        <span class="block text-zinc-500">{{ $item->reason }}</span>
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No stuck requests.') }}</li>
                @endforelse
            </ul>
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Backup check') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Confirms this copy still has the required records. It does not take a snapshot and it does not replace a host backup. Snapshots live on Administration → Backups.') }}</p>
            <div class="mt-4">
                <flux:button variant="primary" wire:click="verifyBackup">{{ __('Check this copy') }}</flux:button>
            </div>
            @if ($backupResult)
                <p class="mt-3 text-sm" data-test="ops-backup-result">{{ $backupResult }}</p>
            @elseif ($this->lastBackup)
                <p class="mt-3 text-sm" data-test="ops-backup-result">
                    {{ $this->lastBackup->ok ? __('Last check passed :time.', ['time' => $this->lastBackup->verified_at->diffForHumans()]) : __('Last check found a problem :time.', ['time' => $this->lastBackup->verified_at->diffForHumans()]) }}
                </p>
            @endif
        </flux:card>

        <flux:card>
            <flux:heading size="sm">{{ __('Consistency check') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Looks for submitted requests without a workflow, or deliveries marked sent that still have a retry time. Nothing is changed.') }}</p>
            <div class="mt-4">
                <flux:button wire:click="reconcile">{{ __('Run check') }}</flux:button>
            </div>
            @if ($reconcileResult)
                <p class="mt-3 text-sm" data-test="ops-reconcile-result">{{ $reconcileResult }}</p>
            @endif
        </flux:card>
    </div>
</div>
