<?php

namespace App\Actions\Collaboration;

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

final class NudgeApproversAction
{
    public const DEFAULT_INTERVAL_MINUTES = 60;

    public const DEFAULT_MAX_PER_DAY = 3;

    public function __construct(private readonly QuietHours $quietHours) {}

    /**
     * @return Collection<int, SubmissionNudge>
     */
    public function execute(Submission $submission, User $actor, ?string $message = null): Collection
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
        $sinceDay = now()->subDay();

        $actorCount = SubmissionNudge::query()
            ->where('actor_id', $actor->id)
            ->where('submission_id', $submission->id)
            ->where('created_at', '>=', $sinceDay)
            ->count();

        if ($actorCount + $recipients->count() > $maxPerDay) {
            throw CollaborationException::cannotNudge("you can send at most {$maxPerDay} reminders on this request in 24 hours");
        }

        $quiet = $this->quietHours->isQuiet();
        $scheduledFor = $this->quietHours->nextOpenAt();
        $trimmed = ($message !== null && trim($message) !== '') ? trim($message) : null;

        return DB::transaction(function () use ($submission, $actor, $recipients, $step, $interval, $quiet, $scheduledFor, $trimmed): Collection {
            $nudges = collect();

            foreach ($recipients as $recipient) {
                $recent = SubmissionNudge::query()
                    ->where('submission_id', $submission->id)
                    ->where('recipient_id', $recipient->id)
                    ->where('created_at', '>=', now()->subMinutes($interval))
                    ->exists();

                if ($recent) {
                    throw CollaborationException::cannotNudge(
                        $recipient->name.' was already reminded in the last '.$interval.' minutes',
                    );
                }

                $nudge = SubmissionNudge::query()->create([
                    'submission_id' => $submission->id,
                    'actor_id' => $actor->id,
                    'recipient_id' => $recipient->id,
                    'step_id' => $step->id,
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
    }

    /**
     * @param  Collection<int, User>  $assignees
     * @return Collection<int, User>
     */
    private function recipients(int $instanceId, int $stepId, Collection $assignees, User $actor): Collection
    {
        $outgoing = Delegation::query()->active()
            ->where('instance_id', $instanceId)
            ->where('step_id', $stepId)
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
