<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

final class DeviceSessionManager
{
    /**
     * @return Collection<int, UserSession>
     */
    public function forUser(User $user): Collection
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();
    }

    public function revoke(User $user, string $sessionId): void
    {
        $session = UserSession::query()
            ->where('user_id', $user->id)
            ->whereKey($sessionId)
            ->firstOrFail();

        $isCurrent = $session->id === Session::getId();
        $session->delete();

        if ($isCurrent) {
            Auth::logout();
            Session::invalidate();
            Session::regenerateToken();
        }
    }

    public function revokeOthers(User $user, string $currentSessionId): int
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    public function revokeAll(User $user): void
    {
        UserSession::query()->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
