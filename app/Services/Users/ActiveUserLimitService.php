<?php

namespace App\Services\Users;

use App\Models\SiteLimit;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActiveUserLimitService
{
    public function maximum(): ?int
    {
        $value = SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->value('max_active_users');

        return $value === null ? null : (int) $value;
    }

    public function activeCount(): int
    {
        return User::query()->where('is_active', true)->count();
    }

    public function remaining(): ?int
    {
        $maximum = $this->maximum();

        if ($maximum === null) {
            return null;
        }

        return max(0, $maximum - $this->activeCount());
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $activate
     * @return TReturn
     */
    public function runActivation(int $slots, Closure $activate): mixed
    {
        return DB::transaction(function () use ($slots, $activate): mixed {
            $limit = SiteLimit::query()->whereKey(SiteLimit::SINGLETON_ID)->lockForUpdate()->firstOrFail();
            $maximum = $limit->max_active_users === null ? null : (int) $limit->max_active_users;

            if ($slots > 0 && $maximum !== null) {
                $active = User::query()->where('is_active', true)->count();

                if (($active + $slots) > $maximum) {
                    throw ValidationException::withMessages([
                        'isActive' => __('The active-user limit has been reached.'),
                    ]);
                }
            }

            return $activate();
        });
    }
}
