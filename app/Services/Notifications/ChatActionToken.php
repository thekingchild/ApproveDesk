<?php

namespace App\Services\Notifications;

use App\Enums\WorkflowActionType;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use App\Support\EmailApprovalLinks;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Signed, time-limited payload for Slack/Teams interactive Approve.
 * Same lifetime as email approval links. GET/message delivery never uses this.
 */
final class ChatActionToken
{
    public static function issue(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        WorkflowActionType $action = WorkflowActionType::Approve,
    ): string {
        $expires = now()->addHours(EmailApprovalLinks::expirationHours())->getTimestamp();
        $payload = implode('|', [
            (string) $instance->id,
            (string) $step->id,
            (string) $approver->id,
            $action->value,
            (string) $expires,
        ]);

        return rtrim(strtr(base64_encode($payload.'.'.hash_hmac('sha256', $payload, self::secret())), '+/', '-_'), '=');
    }

    /**
     * @return array{instance_id: int, step_id: int, approver_id: int, action: WorkflowActionType, expires: int}
     */
    public static function parse(string $token): array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if (! is_string($raw) || ! str_contains($raw, '.')) {
            throw new InvalidArgumentException('Invalid action token.');
        }

        [$payload, $signature] = explode('.', $raw, 2);
        if (! hash_equals(hash_hmac('sha256', $payload, self::secret()), $signature)) {
            throw new InvalidArgumentException('Invalid action token.');
        }

        [$instanceId, $stepId, $approverId, $action, $expires] = array_pad(explode('|', $payload), 5, '');
        $actionType = WorkflowActionType::tryFrom($action);
        if ($actionType === null || $actionType !== WorkflowActionType::Approve) {
            throw new InvalidArgumentException('This action is not supported from chat.');
        }

        if ((int) $expires < Carbon::now()->getTimestamp()) {
            throw new InvalidArgumentException('This approval action has expired.');
        }

        return [
            'instance_id' => (int) $instanceId,
            'step_id' => (int) $stepId,
            'approver_id' => (int) $approverId,
            'action' => $actionType,
            'expires' => (int) $expires,
        ];
    }

    private static function secret(): string
    {
        return (string) config('app.key');
    }
}
