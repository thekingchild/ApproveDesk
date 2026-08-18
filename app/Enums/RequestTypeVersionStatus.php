<?php

namespace App\Enums;

enum RequestTypeVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
