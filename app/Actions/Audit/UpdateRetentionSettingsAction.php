<?php

namespace App\Actions\Audit;

use App\Models\Setting;
use App\Models\User;
use App\Support\RetentionSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Change how long requests are kept. A records-policy change, so it is
 * authorized here as well as on the page (ADR 0008) and recorded in the
 * activity log with the old and new values.
 */
final class UpdateRetentionSettingsAction
{
    public function execute(User $actor, int|string $draftDays, int|string $completedDays): void
    {
        Gate::forUser($actor)->authorize('settings.manage');

        $validated = Validator::make(
            ['draftDays' => $draftDays, 'completedDays' => $completedDays],
            RetentionSettings::rules(),
        )->validate();

        $before = ['draft_days' => RetentionSettings::draftDays(), 'completed_days' => RetentionSettings::completedDays()];
        $after = ['draft_days' => (int) $validated['draftDays'], 'completed_days' => (int) $validated['completedDays']];

        DB::transaction(function () use ($after): void {
            Setting::put(RetentionSettings::DRAFT_DAYS, (string) $after['draft_days']);
            Setting::put(RetentionSettings::COMPLETED_DAYS, (string) $after['completed_days']);
        });

        if ($before !== $after) {
            activity()->causedBy($actor)->event('retention-updated')
                ->withProperties(['old' => $before, 'attributes' => $after])
                ->log('Retention settings changed');
        }
    }
}
