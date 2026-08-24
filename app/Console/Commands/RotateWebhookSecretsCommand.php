<?php

namespace App\Console\Commands;

use App\Actions\Security\RotateWebhookSecretsAction;
use App\Models\WebhookEndpoint;
use Illuminate\Console\Command;

class RotateWebhookSecretsCommand extends Command
{
    protected $signature = 'secrets:rotate-webhooks {endpoint? : Webhook endpoint ID}';

    protected $description = 'Rotate outbound webhook signing secrets. Existing receivers must be updated.';

    public function handle(RotateWebhookSecretsAction $action): int
    {
        $endpoint = null;

        if ($this->argument('endpoint') !== null) {
            $endpoint = WebhookEndpoint::query()->find($this->argument('endpoint'));

            if ($endpoint === null) {
                $this->error('Webhook endpoint not found.');

                return self::FAILURE;
            }
        }

        $rotated = $action->execute($endpoint);

        if ($rotated->isEmpty()) {
            $this->info('No webhook endpoints to rotate.');

            return self::SUCCESS;
        }

        $this->info('Rotated '.$rotated->count().' webhook secret(s). Copy each new secret to the receiving system.');

        foreach ($rotated as $id => $secret) {
            $this->line('#'.$id.': '.$secret);
        }

        return self::SUCCESS;
    }
}
