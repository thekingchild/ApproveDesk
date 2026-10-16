<?php

namespace App\Services;

use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\SubmissionStatus;
use App\Enums\TimeoutAction;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowDelegated;
use App\Events\WorkflowInformationRequested;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Exceptions\WorkflowException;
use App\Models\Delegation;
use App\Models\OutOfOffice;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSelectionRule;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\StepOutcomes;
use App\Services\Workflow\WorkflowTemplateSelector;
use App\Support\SeparationOfDuties;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates every workflow state transition. All mutations to
 * workflow_instances happen here — never from controllers, components,
 * jobs, or commands — inside a transaction with a row lock on the
 * instance so concurrent approvals cannot double-apply.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly SubmissionConditionEvaluator $conditionEvaluator,
        private readonly WorkflowTemplateSelector $selector,
        private readonly StepOutcomes $outcomes,
    ) {}

    /**
     * Bind a submission to its template and activate the first step.
     */
    public function initialize(Submission $submission): WorkflowInstance
    {
        $selection = $this->resolveTemplate($submission);
        $template = $selection['template'];
        $version = $template->publishedVersion;

        if ($version === null || $version->steps->isEmpty()) {
            throw WorkflowException::templateWithoutSteps($template->name);
        }

        $instance = DB::transaction(function () use ($submission, $template, $version, $selection): WorkflowInstance {
            $instance = WorkflowInstance::create([
                'submission_id' => $submission->id,
                'template_id' => $template->id,
                'template_version_id' => $version->id,
                'selection_explanation' => $selection['explanation'],
                'current_step_id' => $version->steps->first()->id,
                'status' => WorkflowStatus::Active,
                'started_at' => now(),
                'step_started_at' => now(),
            ]);

            $submission->update(['status' => SubmissionStatus::InReview]);

            return $this->resolveAutomaticSteps($instance);
        });

        if ($instance->wasCompleted) {
            $this->dispatchEvents($instance);
        }

        return $instance;
    }

    /**
     * Validate, record, and apply an approver action against the current step.
     */
    public function processAction(
        WorkflowInstance $instance,
        User $actor,
        WorkflowActionType $action,
        ?string $comment = null,
        ?string $outcomeKey = null,
    ): WorkflowInstance {
        $applied = DB::transaction(function () use ($instance, $actor, $action, $comment, $outcomeKey): WorkflowInstance {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->with(['currentStep', 'submission', 'templateVersion'])
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($outcomeKey !== null) {
                $outcome = $this->outcomes->find($instance->templateVersion, $outcomeKey);
                if ($outcome === null) {
                    throw WorkflowException::unknownOutcome($outcomeKey);
                }
                $action = $this->outcomes->actionType($outcome);
                if ($outcome['requires_comment'] && trim((string) $comment) === '') {
                    throw WorkflowException::commentRequired($outcomeKey);
                }
            }

            $this->authorize($instance, $actor, $action);

            if (in_array($action, [
                WorkflowActionType::ApproveWithConditions,
                WorkflowActionType::Reject,
                WorkflowActionType::Return,
                WorkflowActionType::RequestInformation,
                WorkflowActionType::Escalate,
            ], true) && trim((string) $comment) === '') {
                throw WorkflowException::commentRequired($action->value);
            }

            WorkflowAction::create([
                'instance_id' => $instance->id,
                'step_id' => $instance->current_step_id,
                'actor_id' => $actor->id,
                'action' => $action,
                'outcome_key' => $outcomeKey ?? $action->value,
                'comment' => $comment,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return match ($action) {
                WorkflowActionType::Approve, WorkflowActionType::ApproveWithConditions => $this->applyApproval($instance),
                WorkflowActionType::Reject => $this->applyRejection($instance),
                WorkflowActionType::Return => $this->applyReturn($instance),
                WorkflowActionType::RequestInformation => $this->applyInformationRequest($instance),
                WorkflowActionType::Escalate => $this->applyEscalation($instance),
                WorkflowActionType::Comment => $instance, // state-neutral, audit-only
                WorkflowActionType::Delegate,
                WorkflowActionType::AutoApprove,
                WorkflowActionType::AutoReject,
                WorkflowActionType::ConditionMatched,
                WorkflowActionType::ConditionNotMatched => throw WorkflowException::unsupportedStepType($action->value),
            };
        });

        $this->dispatchEvents($applied);

        return $applied;
    }

    public function delegate(
        WorkflowInstance $instance,
        User $delegator,
        User $delegate,
        CarbonInterface $expiresAt,
        ?string $comment = null,
        ?CarbonInterface $startsAt = null,
    ): Delegation {
        $startsAt ??= now();

        $delegation = DB::transaction(function () use ($instance, $delegator, $delegate, $expiresAt, $comment, $startsAt): Delegation {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeDelegation($instance, $delegator, $delegate, $expiresAt, $startsAt);
            $step = $instance->currentStep;

            $delegation = Delegation::create([
                'instance_id' => $instance->id,
                'step_id' => $step->id,
                'delegator_id' => $delegator->id,
                'delegate_id' => $delegate->id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
            ]);

            WorkflowAction::create([
                'instance_id' => $instance->id,
                'step_id' => $step->id,
                'actor_id' => $delegator->id,
                'action' => WorkflowActionType::Delegate,
                'comment' => $comment ?? 'Delegated until '.$expiresAt->toIso8601String(),
                'delegated_to' => $delegate->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $delegation->load(['instance.submission', 'delegator', 'delegate', 'step']);
        });

        DB::afterCommit(fn () => event(new WorkflowDelegated($delegation)));

        return $delegation;
    }

    public function processTimeout(WorkflowInstance $instance): WorkflowInstance
    {
        $applied = DB::transaction(function () use ($instance): WorkflowInstance {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $step = $instance->currentStep;
            $deadline = $instance->deadlineAt();

            if ($instance->status !== WorkflowStatus::Active
                || $step === null
                || $step->timeout_action === null
                || $deadline === null
                || $deadline->isFuture()) {
                return $instance;
            }

            return match ($step->timeout_action) {
                TimeoutAction::AutoApprove => $this->applyTimedApproval($instance, $step),
                TimeoutAction::AutoReject => $this->applyTimedRejection($instance, $step),
                TimeoutAction::Escalate => $this->applyTimedEscalation($instance, $step),
            };
        });

        $this->dispatchEvents($applied);

        return $applied;
    }

    /**
     * @throws WorkflowException when the actor may not perform this action
     */
    private function authorize(WorkflowInstance $instance, User $actor, WorkflowActionType $action): void
    {
        if ($instance->status !== WorkflowStatus::Active) {
            throw WorkflowException::instanceNotActive($instance->status->value);
        }

        $step = $instance->currentStep;

        if ($step === null) {
            throw WorkflowException::actorNotAssigned($actor->email, 'none');
        }

        if ($action !== WorkflowActionType::Comment && SeparationOfDuties::forbids($actor, $instance->submission)) {
            throw WorkflowException::selfApprovalNotAllowed();
        }

        $directlyAssigned = $step->assignedUsers($instance->submission)->contains($actor);
        $delegated = $this->hasActiveIncomingDelegation($instance, $step, $actor);

        if (! $directlyAssigned && ! $delegated) {
            throw WorkflowException::actorNotAssigned($actor->email, $step->name);
        }

        if ($directlyAssigned
            && $action !== WorkflowActionType::Comment
            && $this->hasActiveOutgoingDelegation($instance, $step, $actor)) {
            throw WorkflowException::delegationNotAllowed('your authority is currently delegated');
        }

        // Comments are repeatable; state-changing actions are one per actor per step.
        if ($action !== WorkflowActionType::Comment && $this->hasActedOn($instance, $step, $actor)) {
            throw WorkflowException::duplicateAction($actor->email);
        }
    }

    private function authorizeDelegation(
        WorkflowInstance $instance,
        User $delegator,
        User $delegate,
        CarbonInterface $expiresAt,
        CarbonInterface $startsAt,
    ): void {
        if ($instance->status !== WorkflowStatus::Active) {
            throw WorkflowException::instanceNotActive($instance->status->value);
        }

        $step = $instance->currentStep;

        if ($step === null || ! $step->allow_delegation) {
            throw WorkflowException::delegationNotAllowed('the current step disables it');
        }

        if (! $step->assignedUsers($instance->submission)->contains($delegator)) {
            throw WorkflowException::actorNotAssigned($delegator->email, $step->name);
        }

        if ($this->hasActedOn($instance, $step, $delegator)) {
            throw WorkflowException::delegationNotAllowed('you have already decided this step');
        }

        if (! $delegate->is_active || $delegate->is($delegator)) {
            throw WorkflowException::delegationNotAllowed('select a different active user');
        }

        if (SeparationOfDuties::forbids($delegate, $instance->submission)) {
            throw WorkflowException::selfDelegationNotAllowed();
        }

        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw WorkflowException::delegationNotAllowed('the expiry must be in the future');
        }

        if ($startsAt->greaterThanOrEqualTo($expiresAt)) {
            throw WorkflowException::delegationNotAllowed('the start must be before the expiry');
        }

        if ($step->assignedUsers($instance->submission)->contains($delegate)
            || $this->hasOverlappingIncomingDelegation($instance, $step, $delegate, $startsAt, $expiresAt)) {
            throw WorkflowException::delegationNotAllowed('the delegate already holds a seat on this step');
        }

        if ($this->hasOverlappingOutgoingDelegation($instance, $step, $delegator, $startsAt, $expiresAt)) {
            throw WorkflowException::delegationNotAllowed('you already have an active delegation');
        }

        if (OutOfOffice::query()
            ->where('user_id', $delegate->id)
            ->overlapping($startsAt, $expiresAt)
            ->exists()) {
            throw WorkflowException::delegationNotAllowed('the delegate is out of office during this period');
        }
    }

    private function hasActiveIncomingDelegation(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $user,
    ): bool {
        return Delegation::query()->active()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('delegate_id', $user->id)
            ->exists();
    }

    private function hasActiveOutgoingDelegation(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $user,
    ): bool {
        return Delegation::query()->active()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('delegator_id', $user->id)
            ->exists();
    }

    private function hasOverlappingOutgoingDelegation(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $user,
        CarbonInterface $startsAt,
        CarbonInterface $expiresAt,
    ): bool {
        return Delegation::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('delegator_id', $user->id)
            ->where('starts_at', '<', $expiresAt)
            ->where('expires_at', '>', $startsAt)
            ->exists();
    }

    private function hasOverlappingIncomingDelegation(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $user,
        CarbonInterface $startsAt,
        CarbonInterface $expiresAt,
    ): bool {
        return Delegation::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('delegate_id', $user->id)
            ->where('starts_at', '<', $expiresAt)
            ->where('expires_at', '>', $startsAt)
            ->exists();
    }

    private function applyApproval(WorkflowInstance $instance): WorkflowInstance
    {
        if (! $this->isStepSatisfied($instance)) {
            return $instance;
        }

        return $this->resolveAutomaticSteps($this->advance($instance));
    }

    private function applyRejection(WorkflowInstance $instance): WorkflowInstance
    {
        $instance->update([
            'status' => WorkflowStatus::Rejected,
            'completed_at' => now(),
        ]);

        $instance->submission->update(['status' => SubmissionStatus::Rejected]);

        $instance->wasRejected = true;

        return $instance;
    }

    private function applyReturn(WorkflowInstance $instance): WorkflowInstance
    {
        $instance->update(['status' => WorkflowStatus::OnHold]);

        $instance->submission->update(['status' => SubmissionStatus::Returned]);

        return $instance;
    }

    private function applyInformationRequest(WorkflowInstance $instance): WorkflowInstance
    {
        $instance->update(['status' => WorkflowStatus::OnHold]);
        $instance->submission->update(['status' => SubmissionStatus::AwaitingInformation]);
        $instance->informationRequested = true;

        return $instance;
    }

    private function applyEscalation(WorkflowInstance $instance): WorkflowInstance
    {
        $hasLaterStep = WorkflowTemplateStep::query()
            ->where('template_version_id', $instance->template_version_id)
            ->where('step_order', '>', $instance->currentStep->step_order)
            ->exists();

        if (! $hasLaterStep) {
            throw WorkflowException::escalationNotAllowed('the current workflow has no later step');
        }

        return $this->resolveAutomaticSteps($this->advance($instance));
    }

    private function applyTimedApproval(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
    ): WorkflowInstance {
        $this->recordSystemAction(
            $instance,
            $step,
            WorkflowActionType::AutoApprove,
            'Step automatically approved after its SLA deadline elapsed.',
        );

        return $this->resolveAutomaticSteps($this->advance($instance));
    }

    private function applyTimedRejection(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
    ): WorkflowInstance {
        $this->recordSystemAction(
            $instance,
            $step,
            WorkflowActionType::AutoReject,
            'Submission automatically rejected after the step SLA deadline elapsed.',
        );

        return $this->applyRejection($instance);
    }

    private function applyTimedEscalation(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
    ): WorkflowInstance {
        $this->recordSystemAction(
            $instance,
            $step,
            WorkflowActionType::Escalate,
            'Step automatically escalated after its SLA deadline elapsed.',
        );

        return $this->applyEscalation($instance);
    }

    private function isStepSatisfied(WorkflowInstance $instance): bool
    {
        $step = $instance->currentStep;

        $approvals = WorkflowAction::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->whereIn('action', [
                WorkflowActionType::Approve,
                WorkflowActionType::ApproveWithConditions,
            ])
            ->distinct()
            ->count('actor_id');

        $completion = $step->groupCompletion();
        $assigneeCount = $step->assignedUsers($instance->submission)->unique('id')->count();

        return match ($completion) {
            GroupCompletion::FirstResponse => $approvals >= 1,
            GroupCompletion::All => $approvals >= $assigneeCount && $assigneeCount >= 1,
            GroupCompletion::Quorum => $this->isQuorumSatisfied($step, $approvals, $instance->submission),
            null => throw WorkflowException::unsupportedStepType($step->step_type->value),
        };
    }

    private function isQuorumSatisfied(WorkflowTemplateStep $step, int $approvals, ?Submission $submission = null): bool
    {
        $assigneeCount = $step->assignedUsers($submission)->unique('id')->count();
        $required = $step->quorum_count;

        if ($required === null || $required < 1 || $required > $assigneeCount) {
            throw WorkflowException::invalidQuorum($step->name, $required, $assigneeCount);
        }

        return $approvals >= $required;
    }

    private function advance(WorkflowInstance $instance): WorkflowInstance
    {
        $nextStep = WorkflowTemplateStep::query()
            ->where('template_version_id', $instance->template_version_id)
            ->where('step_order', '>', $instance->currentStep->step_order)
            ->orderBy('step_order')
            ->first();

        if ($nextStep === null) {
            return $this->complete($instance);
        }

        $instance->update([
            'current_step_id' => $nextStep->id,
            'step_started_at' => now(),
        ]);

        $instance->advancedToStep = $nextStep;

        return $instance;
    }

    private function resolveAutomaticSteps(WorkflowInstance $instance): WorkflowInstance
    {
        $maximumTransitions = WorkflowTemplateStep::query()
            ->where('template_version_id', $instance->template_version_id)
            ->count() + 1;

        for ($transition = 0; $transition < $maximumTransitions; $transition++) {
            $instance->refresh();
            $step = $instance->currentStep;

            if ($instance->status !== WorkflowStatus::Active || $step === null) {
                return $instance;
            }

            if ($step->step_type === StepType::Auto || $step->step_type === StepType::Notification) {
                $message = $step->step_type === StepType::Notification
                    ? 'Notification step completed automatically.'
                    : 'Automatically approved by workflow policy.';
                $this->recordSystemAction($instance, $step, WorkflowActionType::AutoApprove, $message);
                $instance = $this->advance($instance);

                continue;
            }

            if ($step->step_type === StepType::Conditional) {
                $instance = $this->routeConditionalStep($instance, $step);

                continue;
            }

            if ($step->assignedUsers($instance->submission)->isEmpty()) {
                if (app(AssigneeResolver::class)->onlyRequesterResolves($step, $instance->submission)) {
                    throw WorkflowException::onlyApproverIsRequester($step->name);
                }

                throw WorkflowException::unresolvedAssignees($step->name);
            }

            return $instance;
        }

        throw WorkflowException::invalidCondition('automatic routing', 'too many automatic transitions');
    }

    private function routeConditionalStep(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
    ): WorkflowInstance {
        $conditions = $step->conditions;

        if (! is_array($conditions)
            || ! array_key_exists('true_step', $conditions)
            || ! array_key_exists('false_step', $conditions)) {
            throw WorkflowException::invalidCondition($step->name, 'true_step and false_step targets are required');
        }

        try {
            $matched = $this->conditionEvaluator->evaluate($instance->submission, $conditions);
        } catch (InvalidArgumentException $exception) {
            throw WorkflowException::invalidCondition($step->name, $exception->getMessage());
        }

        $this->recordSystemAction(
            $instance,
            $step,
            $matched ? WorkflowActionType::ConditionMatched : WorkflowActionType::ConditionNotMatched,
            $matched ? 'Condition matched; followed the true branch.' : 'Condition did not match; followed the false branch.',
        );

        $target = $matched ? $conditions['true_step'] : $conditions['false_step'];

        if ($target === null) {
            return $this->complete($instance);
        }

        if (! is_int($target) || $target <= $step->step_order) {
            throw WorkflowException::invalidCondition($step->name, 'each branch must target a forward step or completion');
        }

        $nextStep = WorkflowTemplateStep::query()
            ->where('template_version_id', $instance->template_version_id)
            ->where('step_order', $target)
            ->first();

        if ($nextStep === null) {
            throw WorkflowException::invalidCondition($step->name, "target step {$target} does not exist");
        }

        $instance->update([
            'current_step_id' => $nextStep->id,
            'step_started_at' => now(),
        ]);
        $instance->advancedToStep = $nextStep;

        return $instance;
    }

    private function recordSystemAction(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        WorkflowActionType $action,
        string $comment,
    ): void {
        WorkflowAction::create([
            'instance_id' => $instance->id,
            'step_id' => $step->id,
            'actor_id' => null,
            'action' => $action,
            'comment' => $comment,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    private function complete(WorkflowInstance $instance): WorkflowInstance
    {
        $instance->update([
            'current_step_id' => null,
            'step_started_at' => null,
            'status' => WorkflowStatus::Completed,
            'completed_at' => now(),
        ]);

        $instance->submission->update(['status' => SubmissionStatus::Approved]);
        $instance->wasCompleted = true;

        return $instance;
    }

    /**
     * @return array{template: WorkflowTemplate, explanation: string, rule: WorkflowSelectionRule|null}
     */
    private function resolveTemplate(Submission $submission): array
    {
        try {
            return $this->selector->select($submission);
        } catch (InvalidArgumentException $exception) {
            throw WorkflowException::noTemplateFor($submission->type->value);
        }
    }

    private function hasActedOn(WorkflowInstance $instance, WorkflowTemplateStep $step, User $actor): bool
    {
        return WorkflowAction::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('actor_id', $actor->id)
            ->whereIn('action', [
                WorkflowActionType::Approve,
                WorkflowActionType::ApproveWithConditions,
                WorkflowActionType::Reject,
                WorkflowActionType::Escalate,
            ])
            ->exists();
    }

    /**
     * Reactivate an instance that was put on hold by a return-for-revision.
     * The current step is preserved, so the chain resumes where it stopped.
     */
    public function resume(WorkflowInstance $instance): WorkflowInstance
    {
        $resumed = DB::transaction(function () use ($instance): WorkflowInstance {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($instance->status !== WorkflowStatus::OnHold) {
                throw WorkflowException::instanceNotActive($instance->status->value);
            }

            $instance->update([
                'status' => WorkflowStatus::Active,
                'step_started_at' => now(),
            ]);

            $instance->submission->update(['status' => SubmissionStatus::InReview]);

            // Resuming does not change step, but it does need the same
            // current-approver notification emitted after an advance.
            $instance->advancedToStep = $instance->currentStep;

            return $instance;
        });

        // Re-notify the approvers of the step the chain resumes on.
        $this->dispatchEvents($resumed);

        return $resumed;
    }

    /**
     * Domain events fire only after the transaction commits, so listeners
     * never observe uncommitted state.
     */
    private function dispatchEvents(WorkflowInstance $instance): void
    {
        DB::afterCommit(function () use ($instance): void {
            if ($instance->wasCompleted) {
                event(new WorkflowCompleted($instance));
            } elseif ($instance->wasRejected) {
                event(new WorkflowRejected($instance));
            } elseif ($instance->status === WorkflowStatus::OnHold) {
                event($instance->informationRequested
                    ? new WorkflowInformationRequested($instance)
                    : new WorkflowReturned($instance));
            } elseif ($instance->advancedToStep !== null) {
                event(new WorkflowAdvanced($instance));
            }
        });
    }
}
