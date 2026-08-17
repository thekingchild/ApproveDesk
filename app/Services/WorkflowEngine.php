<?php

namespace App\Services;

use App\Enums\StepType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowDelegated;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Exceptions\WorkflowException;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
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
    public function __construct(private readonly SubmissionConditionEvaluator $conditionEvaluator) {}

    /**
     * Bind a submission to its template and activate the first step.
     */
    public function initialize(Submission $submission): WorkflowInstance
    {
        $template = $this->resolveTemplate($submission);

        if ($template->steps->isEmpty()) {
            throw WorkflowException::templateWithoutSteps($template->name);
        }

        $instance = DB::transaction(function () use ($submission, $template): WorkflowInstance {
            $instance = WorkflowInstance::create([
                'submission_id' => $submission->id,
                'template_id' => $template->id,
                'current_step_id' => $template->steps->first()->id,
                'status' => WorkflowStatus::Active,
                'started_at' => now(),
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
    ): WorkflowInstance {
        $applied = DB::transaction(function () use ($instance, $actor, $action, $comment): WorkflowInstance {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize($instance, $actor, $action);

            WorkflowAction::create([
                'instance_id' => $instance->id,
                'step_id' => $instance->current_step_id,
                'actor_id' => $actor->id,
                'action' => $action,
                'comment' => $comment,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return match ($action) {
                WorkflowActionType::Approve => $this->applyApproval($instance),
                WorkflowActionType::Reject => $this->applyRejection($instance),
                WorkflowActionType::Return => $this->applyReturn($instance),
                WorkflowActionType::Comment => $instance, // state-neutral, audit-only
                WorkflowActionType::Delegate,
                WorkflowActionType::Escalate,
                WorkflowActionType::AutoApprove,
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
    ): Delegation {
        $delegation = DB::transaction(function () use ($instance, $delegator, $delegate, $expiresAt, $comment): Delegation {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeDelegation($instance, $delegator, $delegate, $expiresAt);
            $step = $instance->currentStep;

            $delegation = Delegation::create([
                'instance_id' => $instance->id,
                'step_id' => $step->id,
                'delegator_id' => $delegator->id,
                'delegate_id' => $delegate->id,
                'starts_at' => now(),
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

        $directlyAssigned = $step->assignedUsers()->contains($actor);
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
    ): void {
        if ($instance->status !== WorkflowStatus::Active) {
            throw WorkflowException::instanceNotActive($instance->status->value);
        }

        $step = $instance->currentStep;

        if ($step === null || ! $step->allow_delegation) {
            throw WorkflowException::delegationNotAllowed('the current step disables it');
        }

        if (! $step->assignedUsers()->contains($delegator)) {
            throw WorkflowException::actorNotAssigned($delegator->email, $step->name);
        }

        if ($this->hasActedOn($instance, $step, $delegator)) {
            throw WorkflowException::delegationNotAllowed('you have already decided this step');
        }

        if (! $delegate->is_active || $delegate->is($delegator)) {
            throw WorkflowException::delegationNotAllowed('select a different active user');
        }

        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw WorkflowException::delegationNotAllowed('the expiry must be in the future');
        }

        if ($step->assignedUsers()->contains($delegate)
            || $this->hasActiveIncomingDelegation($instance, $step, $delegate)) {
            throw WorkflowException::delegationNotAllowed('the delegate already holds a seat on this step');
        }

        if ($this->hasActiveOutgoingDelegation($instance, $step, $delegator)) {
            throw WorkflowException::delegationNotAllowed('you already have an active delegation');
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

    private function isStepSatisfied(WorkflowInstance $instance): bool
    {
        $step = $instance->currentStep;

        $approvals = WorkflowAction::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('action', WorkflowActionType::Approve)
            ->distinct()
            ->count('actor_id');

        return match ($step->step_type) {
            StepType::Sequential => $approvals >= 1,
            StepType::Parallel => $approvals >= $step->assignedUsers()->unique('id')->count(),
            StepType::Quorum => $this->isQuorumSatisfied($step, $approvals),
            StepType::Conditional, StepType::Auto => throw WorkflowException::unsupportedStepType($step->step_type->value),
        };
    }

    private function isQuorumSatisfied(WorkflowTemplateStep $step, int $approvals): bool
    {
        $assigneeCount = $step->assignedUsers()->unique('id')->count();
        $required = $step->quorum_count;

        if ($required === null || $required < 1 || $required > $assigneeCount) {
            throw WorkflowException::invalidQuorum($step->name, $required, $assigneeCount);
        }

        return $approvals >= $required;
    }

    private function advance(WorkflowInstance $instance): WorkflowInstance
    {
        $nextStep = WorkflowTemplateStep::query()
            ->where('template_id', $instance->template_id)
            ->where('step_order', '>', $instance->currentStep->step_order)
            ->orderBy('step_order')
            ->first();

        if ($nextStep === null) {
            return $this->complete($instance);
        }

        $instance->update(['current_step_id' => $nextStep->id]);

        $instance->advancedToStep = $nextStep;

        return $instance;
    }

    private function resolveAutomaticSteps(WorkflowInstance $instance): WorkflowInstance
    {
        $maximumTransitions = WorkflowTemplateStep::query()
            ->where('template_id', $instance->template_id)
            ->count() + 1;

        for ($transition = 0; $transition < $maximumTransitions; $transition++) {
            $instance->refresh();
            $step = $instance->currentStep;

            if ($instance->status !== WorkflowStatus::Active || $step === null) {
                return $instance;
            }

            if ($step->step_type === StepType::Auto) {
                $this->recordSystemAction($instance, $step, WorkflowActionType::AutoApprove, 'Automatically approved by workflow policy.');
                $instance = $this->advance($instance);

                continue;
            }

            if ($step->step_type === StepType::Conditional) {
                $instance = $this->routeConditionalStep($instance, $step);

                continue;
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
            ->where('template_id', $instance->template_id)
            ->where('step_order', $target)
            ->first();

        if ($nextStep === null) {
            throw WorkflowException::invalidCondition($step->name, "target step {$target} does not exist");
        }

        $instance->update(['current_step_id' => $nextStep->id]);
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
            'status' => WorkflowStatus::Completed,
            'completed_at' => now(),
        ]);

        $instance->submission->update(['status' => SubmissionStatus::Approved]);
        $instance->wasCompleted = true;

        return $instance;
    }

    private function resolveTemplate(Submission $submission): WorkflowTemplate
    {
        $template = WorkflowTemplate::query()
            ->where('is_active', true)
            ->whereJsonContains('submission_types', $submission->type->value)
            ->orderByDesc('is_default')
            ->first()
            ?? WorkflowTemplate::query()
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();

        return $template ?? throw WorkflowException::noTemplateFor($submission->type->value);
    }

    private function hasActedOn(WorkflowInstance $instance, WorkflowTemplateStep $step, User $actor): bool
    {
        return WorkflowAction::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $step->id)
            ->where('actor_id', $actor->id)
            ->whereIn('action', [
                WorkflowActionType::Approve,
                WorkflowActionType::Reject,
                WorkflowActionType::Return,
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

            $instance->update(['status' => WorkflowStatus::Active]);

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
                event(new WorkflowReturned($instance));
            } elseif ($instance->advancedToStep !== null) {
                event(new WorkflowAdvanced($instance));
            }
        });
    }
}
