<?php

namespace App\Actions\RequestTypes;

use App\Models\RequestType;

/**
 * Re-opens an archived request type for new submissions. This is the only
 * way back from Archive: saving or publishing a draft leaves it archived
 * (RV-BUG-31).
 */
final class RestoreRequestTypeAction
{
    public function execute(RequestType $requestType): void
    {
        $requestType->update(['is_active' => true]);
    }
}
