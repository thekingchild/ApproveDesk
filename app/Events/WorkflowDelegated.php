<?php

namespace App\Events;

use App\Models\Delegation;

final class WorkflowDelegated
{
    public function __construct(public readonly Delegation $delegation) {}
}
