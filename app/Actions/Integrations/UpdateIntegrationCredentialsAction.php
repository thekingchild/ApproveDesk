<?php

namespace App\Actions\Integrations;

use App\Models\IntegrationConnector;
use App\Models\User;
use App\Support\OutboundDestination;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves or clears a connector's credentials (RV-ARC-11). Blank fields keep
 * the stored value; URL fields must point at a public HTTPS destination
 * (SSRF guard). The activity log records which fields changed, never values.
 */
final class UpdateIntegrationCredentialsAction
{
    public function __construct(private readonly OutboundDestination $destinations) {}

    /**
     * @param  array<string, mixed>  $input  credential fields as submitted
     *
     * @throws ValidationException when a URL field points somewhere it must not
     */
    public function execute(IntegrationConnector $connector, array $input, User $actor): IntegrationConnector
    {
        Gate::forUser($actor)->authorize('update', $connector);

        $credentials = $connector->credentialMap();
        $changed = [];

        foreach ($connector->type->credentialFields() as $field) {
            $value = trim(is_scalar($input[$field] ?? null) ? (string) $input[$field] : '');
            if ($value === '') {
                continue;
            }

            if (self::isDestinationUrl($field)) {
                try {
                    $this->destinations->validate($value);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$field => $exception->getMessage()]);
                }
            }

            if (($credentials[$field] ?? null) !== $value) {
                $changed[] = $field;
            }
            $credentials[$field] = $value;
        }

        $connector->forceFill(['credentials' => $credentials])->save();
        $this->log($connector, $actor, 'integration-credentials-changed', $changed);

        return $connector;
    }

    public function clear(IntegrationConnector $connector, User $actor): IntegrationConnector
    {
        Gate::forUser($actor)->authorize('update', $connector);

        $connector->forceFill(['credentials' => null, 'last_error' => null])->save();
        $this->log($connector, $actor, 'integration-credentials-cleared', []);

        return $connector;
    }

    private static function isDestinationUrl(string $field): bool
    {
        return in_array($field, ['url', 'webhook_url', 'site_url'], true) || str_ends_with($field, '_url');
    }

    /** @param list<string> $fields */
    private function log(IntegrationConnector $connector, User $actor, string $event, array $fields): void
    {
        activity()->performedOn($connector)->causedBy($actor)
            ->event($event)
            ->withProperties(['connector' => $connector->type->value, 'fields' => $fields])
            ->log($event === 'integration-credentials-cleared' ? 'Integration credentials cleared' : 'Integration credentials saved');
    }
}
