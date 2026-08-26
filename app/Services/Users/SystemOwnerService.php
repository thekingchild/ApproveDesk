<?php

namespace App\Services\Users;

use App\Models\SystemOwner;

final class SystemOwnerService
{
    private bool $resolved = false;

    private ?int $ownerId = null;

    public function ownerId(): ?int
    {
        if (! $this->resolved) {
            $id = SystemOwner::query()->whereKey(SystemOwner::SINGLETON_ID)->value('user_id');
            $this->ownerId = $id === null ? null : (int) $id;
            $this->resolved = true;
        }

        return $this->ownerId;
    }

    public function forget(): void
    {
        $this->resolved = false;
        $this->ownerId = null;
    }
}
