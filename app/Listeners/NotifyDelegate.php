<?php

namespace App\Listeners;

use App\Events\WorkflowDelegated;
use App\Notifications\DelegationAssignedNotification;

class NotifyDelegate
{
    public function handle(WorkflowDelegated $event): void
    {
        $event->delegation->delegate->notify(new DelegationAssignedNotification($event->delegation));
    }
}
