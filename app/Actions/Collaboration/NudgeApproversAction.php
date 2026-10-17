<?php

namespace App\Actions\Collaboration;

use App\DTOs\NudgeResult;
use App\Enums\WorkflowStatus;
use App\Exceptions\CollaborationException;
use App\Models\Delegation;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\SubmissionNudge;
use App\Models\User;
use App\Notifications\SubmissionNudgeNotification;
use App\Services\Collaboration\QuietHours;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NudgeApproversAction
{
    public const DEFAULT_INTERVAL_MINUTES = 60;

    public const DEFAULT_MAX_PER_DAY = 3;

    public function __construct(private readonly QuietHours $quietHours) {}

    /**
     * Reminds every current approver. The daily cap counts reminder actions
     * by this person, and anyone reminded within the interval is skipped and
     * reported back rather than blocking the others (RV-BUG-19).
     */
    public function execute(Submission $submission, User $actor, ?string $message = null): NudgeResult
    {
        if (! $actor->can('nudge', $submission)) {
            throw CollaborationException::cannotNudge('you do not have access to this request');
        }

        $instance = $submission->workflowInstance;
        $step = $instance?->currentStep;

        if ($instance === null || $instance->status !== WorkflowStatus::Active || $step === null) {
            throw CollaborationException::cannotNudge('there is no active approval step to remind');
        }

        $recipients = $this->recipients($instance->id, $step->id, $step->assignedUsers($instance->submission), $actor);
        if ($recipients->isEmpty()) {
            throw CollaborationException::cannotNudge('there is no current approver to remind');
        }

        $interval = max(1, (int) Setting::get('nudge.rate_limit_minutes', (string) self::DEFAULT_INTERVAL_MINUTES));
        $maxPerDay = max(1, (int) Setting::get('nudge.max_per_day', (string) self::DEFAULT_MAX_PER_DAY));

        if ($this->actionsInLastDay($submission, $actor) >= $maxPerDay) {
            throw CollaborationException::cannotNudge("you can send at most {$maxPerDay} reminders on this request in 24 hours");
        }

        $recentlyReminded = SubmissionNudge::query()
            ->where('submission_id', $submission->id)
            ->whereIn('recipient_id', $recipients->pluck('id'))
            ->where('created_at', '>=', now()->subMinutes($interval))
            ->distinct()
            ->pluck('recipient_id');

        [$skipped, $toRemind] = $recipients->partition(fn (User $user): bool => $recentlyReminded->contains($user->id));

        if ($toRemind->isEmpty()) {
            throw CollaborationException::cannotNudge(
                $skipped->pluck('name')->join(', ', ' and ').' '.($skipped->count() === 1 ? 'was' : 'were')
                .' already reminded in the last '.$interval.' minutes',
            );
        }

        $quiet = $this->quietHours->isQuiet();
        $scheduledFor = $this->quietHours->nextOpenAt();
        $trimmed = ($message !== null && trim($message) !== '') ? trim($message) : null;
        $batchId = (string) Str::uuid();

        $nudges = DB::transaction(function () use ($submission, $actor, $toRemind, $step, $quiet, $scheduledFor, $trimmed, $batchId): Collection {
            $nudges = collect();

            foreach ($toRemind as $recipient) {
                $nudge = SubmissionNudge::query()->create([
                    'submission_id' => $submission->id,
                    'actor_id' => $actor->id,
                    'recipient_id' => $recipient->id,
                    'step_id' => $step->id,
                    'batch_id' => $batchId,
                    'message' => $trimmed,
                    'scheduled_for' => $scheduledFor,
                    'delayed_for_quiet_hours' => $quiet,
                ]);

                $notification = (new SubmissionNudgeNotification($nudge->load(['submission', 'actor'])))
                    ->delay($quiet ? $scheduledFor : now());
                $recipient->notify($notification);

                $nudges->push($nudge);
            }

            return $nudges;
        });

        return new NudgeResult($nudges, $skipped->values());
    }

    /**
     * One click is one action, however many approvers it reaches. Rows from
     * before batch_id existed count as one action each.
     */
    private function actionsInLastDay(Submission $submission, User $actor): int
    {
        return SubmissionNudge::query()
            ->where('actor_id', $actor->id)
            ->where('submission_id', $submission->id)
            ->where('created_at', '>=', now()->subDay())
            ->get(['id', 'batch_id'])
            ->map(fn (SubmissionNudge $nudge): string => $nudge->batch_id ?? 'row-'.$nudge->id)
            ->unique()
            ->count();
    }

    /**
     * @param  Collection<int, User>  $assignees
     * @return Collection<int, User>
     */
    private function recipients(int $instanceId, int $stepId, Collection $assignees, User $actor): Collection
    {
        // A delegation to a deactivated person no longer covers the delegator (RV-ENG-12).
        $outgoing = Delegation::query()->active()
            ->where('instance_id', $instanceId)
            ->where('step_id', $stepId)
            ->whereHas('delegate', fn ($delegate) => $delegate->where('is_active', true))
            ->pluck('delegator_id');

        $delegates = Delegation::query()->active()
            ->where('instance_id', $instanceId)
            ->where('step_id', $stepId)
            ->with('delegate')
            ->get()
            ->pluck('delegate')
            ->filter();

        return $assignees
            ->reject(fn (User $user): bool => $outgoing->contains($user->id))
            ->concat($delegates)
            ->filter(fn (User $user): bool => $user->is_active && ! $user->is($actor))
            ->unique('id')
            ->values();
    }
}
