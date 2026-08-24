<?php

namespace App\Actions\Updates;

use App\Models\Setting;
use App\Models\User;
use App\Services\Operations\MigrationPreflight;
use App\Support\CorrelationId;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ApplyDatabaseUpdatesAction
{
    public const CONFIRMATION = 'APPLY';

    public function __construct(private readonly MigrationPreflight $preflight) {}

    public function execute(User $actor, string $confirmation): string
    {
        if ($confirmation !== self::CONFIRMATION) {
            throw new InvalidArgumentException('Type APPLY to confirm these database updates.');
        }

        $flagged = $this->preflight->inspectPending();

        if ($flagged !== []) {
            $summary = collect($flagged)
                ->map(fn (array $flags, string $file): string => $file.': '.implode(', ', $flags))
                ->implode('; ');

            throw new InvalidArgumentException('Pending updates include unsafe changes. '.$summary);
        }

        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        Setting::put('updates.last_applied_at', now()->toIso8601String());

        Log::info('updates.applied', [
            'actor_id' => $actor->id,
            'correlation_id' => CorrelationId::current(),
        ]);

        if ($output === '' || str_contains($output, 'Nothing to migrate')) {
            return 'Nothing to apply.';
        }

        return $output;
    }
}
