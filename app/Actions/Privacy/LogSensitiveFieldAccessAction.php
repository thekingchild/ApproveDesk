<?php

namespace App\Actions\Privacy;

use App\Models\RequestFieldDefinition;
use App\Models\SensitiveFieldAccess;
use App\Models\Submission;
use App\Models\User;

final class LogSensitiveFieldAccessAction
{
    public function execute(User $user, Submission $submission, RequestFieldDefinition $field, string $context): void
    {
        if (! $field->is_sensitive || $submission->submitter_id === $user->id) {
            return;
        }

        $recent = SensitiveFieldAccess::query()
            ->where('user_id', $user->id)
            ->where('submission_id', $submission->id)
            ->where('request_field_definition_id', $field->id)
            ->where('context', $context)
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if ($recent) {
            return;
        }

        SensitiveFieldAccess::query()->create([
            'user_id' => $user->id,
            'submission_id' => $submission->id,
            'request_field_definition_id' => $field->id,
            'context' => $context,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
