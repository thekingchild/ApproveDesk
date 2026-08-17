<?php

namespace App\Events;

use App\Models\WorkflowInstance;

final class WorkflowInformationRequested
{
    public function __construct(public readonly WorkflowInstance $instance) {}
}
