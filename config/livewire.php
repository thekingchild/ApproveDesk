<?php

/*
| Only the settings ApproveDesk overrides; Livewire merges its own defaults
| for everything else (vendor/livewire/livewire/config/livewire.php).
*/

return [

    /*
    | Alpine's CSP-safe build (ADR 0005 step 2). Off by default: Flux is not yet
    | verified under it. Turn it on with LIVEWIRE_CSP_SAFE=true on a staging
    | site; ApproveDesk then also sends the strict policy in report-only mode,
    | and the reports show what would break before step 3 enforces it.
    */
    'csp_safe' => (bool) env('LIVEWIRE_CSP_SAFE', false),

];
