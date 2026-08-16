<?php

namespace App\Events;

use App\Models\WorkflowInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowAdvanced
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly WorkflowInstance $instance) {}
}
