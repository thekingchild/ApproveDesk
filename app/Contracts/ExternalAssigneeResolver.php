<?php

namespace App\Contracts;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplateStep;
use Illuminate\Support\Collection;

interface ExternalAssigneeResolver
{
    public function key(): string;

    /**
     * @return Collection<int, User>
     */
    public function resolve(WorkflowTemplateStep $step, Submission $submission): Collection;
}
