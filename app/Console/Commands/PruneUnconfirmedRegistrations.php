<?php

namespace App\Console\Commands;

use App\Enums\SystemRole;
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

        $deleted = 0;
        User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $cutoff)
            // Only self-registrations that never confirmed their code. Someone who
            // changed their email on Profile is also unverified but has no
            // registration code, and must never be deleted (RV-BUG-06).
            ->whereExists(fn ($codes) => $codes->selectRaw('1')
                ->from('registration_otp_codes')
                ->whereColumn('registration_otp_codes.email', 'users.email'))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', SystemRole::SuperAdmin->value))
            // Page by id: offset paging skips rows when deleting as it goes.
            ->lazyById()
            ->each(function (User $user) use (&$deleted): void {
                // Unconfirmed accounts can still appear in request history (for
                // example as a delegate); those are kept for the audit trail.
                if ($user->hasPreservedAuditEvidence()) {
                    return;
                }

                $user->delete();
                $deleted++;
            });

        $this->info("Pruned {$deleted} unconfirmed registration(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
