<?php

namespace App\Enums;

enum WorkflowTemplateVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
    case Archived = 'archived';

    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }
}
