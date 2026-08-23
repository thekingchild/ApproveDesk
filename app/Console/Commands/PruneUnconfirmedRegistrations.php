<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Phase UO.3: prune self-registrations that were never confirmed within
 * 72 hours, freeing the email address for re-registration.
 */
class PruneUnconfirmedRegistrations extends Command
{
    protected $signature = 'users:prune-unconfirmed {--hours=72}';

    protected $description = 'Delete self-registered accounts whose email was never confirmed within the grace window.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $deleted = User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->delete();

        $this->info("Pruned {$deleted} unconfirmed registration(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
