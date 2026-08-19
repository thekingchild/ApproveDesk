<?php

namespace App\Actions\RequestTypes;

use App\Models\RequestType;

final class ArchiveRequestTypeAction
{
    public function execute(RequestType $requestType): void
    {
        $requestType->update(['is_active' => false]);
    }
}
