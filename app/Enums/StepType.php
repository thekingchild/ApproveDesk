<?php

namespace App\Enums;

enum StepType: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Quorum = 'quorum';
    case Conditional = 'conditional';
    case Auto = 'auto';
}
