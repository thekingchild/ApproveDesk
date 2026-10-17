<?php

namespace App\Enums;

/** Whether this session has recently re-proved who is signed in (RV-SEC-16). */
enum StepUpStatus: string
{
    case Satisfied = 'satisfied';
    case NeedsPassword = 'needs_password';
    case NeedsSecondFactor = 'needs_second_factor';
}
