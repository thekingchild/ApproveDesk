<?php

namespace App\DTOs;

use App\Models\SubmissionNudge;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Outcome of one "Remind approver" action: who was reminded, and who was
 * left out because they were reminded too recently (RV-BUG-19).
 */
final readonly class NudgeResult
{
    /**
     * @param  Collection<int, SubmissionNudge>  $nudges
     * @param  Collection<int, User>  $skipped
     */
    public function __construct(
        public Collection $nudges,
        public Collection $skipped,
    ) {}
}
