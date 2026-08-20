<?php

namespace App\Listeners;

use App\Events\SubmissionCreated;
use App\Events\WorkflowAdvanced;
use App\Services\Collaboration\OutOfOfficeFallback;

class ApplyOutOfOfficeFallbacks
{
    public function __construct(private readonly OutOfOfficeFallback $fallbacks) {}

    public function handle(SubmissionCreated|WorkflowAdvanced $event): void
    {
        $instance = match (true) {
            $event instanceof SubmissionCreated => $event->instance ?? $event->submission->workflowInstance()->first(),
            default => $event->instance,
        };

        if ($instance === null) {
            return;
        }

        $this->fallbacks->applyToInstance($instance);
    }
}
