<?php

namespace App\Actions\Settings;

use App\Models\SiteLimit;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateActiveUserLimitAction
{
    public function __construct(private readonly ActiveUserLimitService $limits) {}

    public function execute(?int $maximum, User $actor): SiteLimit
    {
        Gate::forUser($actor)->authorize('updateActiveUserLimit', User::class);

        if ($maximum !== null && $maximum < 1) {
            throw ValidationException::withMessages([
                'maxActiveUsers' => __('The active-user limit must be at least 1 or left unlimited.'),
            ]);
        }

        return DB::transaction(function () use ($maximum): SiteLimit {
            $limit = SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->lockForUpdate()->firstOrFail();
            $active = $this->limits->activeCount();

            if ($maximum !== null && $maximum < $active) {
                throw ValidationException::withMessages([
                    'maxActiveUsers' => __('The limit cannot be lower than the current number of active users.'),
                ]);
            }

            $limit->update(['max_active_users' => $maximum]);

            return $limit->refresh();
        });
    }
}
