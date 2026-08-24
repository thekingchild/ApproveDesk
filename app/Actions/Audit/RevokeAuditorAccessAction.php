<?php

namespace App\Actions\Audit;

use App\Models\AuditorAccess;
use App\Models\User;
use InvalidArgumentException;

final class RevokeAuditorAccessAction
{
    public function execute(User $actor, AuditorAccess $access): AuditorAccess
    {
        if ($access->revoked_at !== null) {
            throw new InvalidArgumentException('That grant is already revoked.');
        }

        $access->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ])->save();

        return $access;
    }
}
