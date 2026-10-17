<?php

namespace App\Support;

use App\Enums\WorkflowActionType;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplateStep;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Every link that approves outside a signed-in session (RV-ARC-18): the email
 * confirmation and approve URLs, and the token behind a chat card's Approve button.
 *
 * Both channels share one lifetime and one second-factor setting, and both are
 * signed with the application key, accepting previous keys after a key rotation.
 * The chat token keeps its original format, so cards already sent keep working.
 */
final class ApprovalLinks
{
    public const DEFAULT_EXPIRATION_HOURS = 72;

    public const MAX_EXPIRATION_HOURS = 720;

    public static function expirationHours(): int
    {
        return max(1, min(
            self::MAX_EXPIRATION_HOURS,
            (int) Setting::get('approvals.email_link_expiration_hours', (string) self::DEFAULT_EXPIRATION_HOURS),
        ));
    }

    public static function requiresSecondFactor(): bool
    {
        return Setting::get('approvals.email_require_second_factor', '0') === '1';
    }

    public static function expiresAt(): CarbonInterface
    {
        return now()->addHours(self::expirationHours());
    }

    /** The page an email or chat card links to; it shows the request before anything is approved. */
    public static function confirmationUrl(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        ?CarbonInterface $expiresAt = null,
    ): string {
        return URL::temporarySignedRoute(
            'approvals.email.confirm',
            $expiresAt ?? self::expiresAt(),
            ['instance' => $instance, 'step' => $step, 'approver' => $approver],
        );
    }

    /** The confirmation page's Approve form target; it expires with the link that opened the page. */
    public static function approvalUrl(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        CarbonInterface $expiresAt,
    ): string {
        return URL::temporarySignedRoute(
            'approvals.email.approve',
            $expiresAt,
            ['instance' => $instance, 'step' => $step, 'approver' => $approver],
        );
    }

    /** The value a Slack or Teams Approve button sends back. */
    public static function chatToken(
        WorkflowInstance $instance,
        WorkflowTemplateStep $step,
        User $approver,
        WorkflowActionType $action = WorkflowActionType::Approve,
    ): string {
        $payload = implode('|', [
            (string) $instance->id,
            (string) $step->id,
            (string) $approver->id,
            $action->value,
            (string) self::expiresAt()->getTimestamp(),
        ]);

        return rtrim(strtr(base64_encode($payload.'.'.self::sign($payload, self::currentKey())), '+/', '-_'), '=');
    }

    /**
     * @return array{instance_id: int, step_id: int, approver_id: int, action: WorkflowActionType, expires: int}
     */
    public static function parseChatToken(string $token): array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if (! is_string($raw) || ! str_contains($raw, '.')) {
            throw new InvalidArgumentException('Invalid action token.');
        }

        [$payload, $signature] = explode('.', $raw, 2);
        if (! self::hasValidSignature($payload, $signature)) {
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

    /** The current key, then any previous keys, as signed URLs accept them. */
    private static function hasValidSignature(string $payload, string $signature): bool
    {
        foreach ([self::currentKey(), ...self::previousKeys()] as $key) {
            if (hash_equals(self::sign($payload, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function sign(string $payload, string $key): string
    {
        return hash_hmac('sha256', $payload, $key);
    }

    private static function currentKey(): string
    {
        return (string) config('app.key');
    }

    /** @return list<string> */
    private static function previousKeys(): array
    {
        $keys = config('app.previous_keys', []);

        return is_array($keys) ? array_values(array_map(fn ($key): string => (string) $key, array_filter($keys, is_string(...)))) : [];
    }
}
