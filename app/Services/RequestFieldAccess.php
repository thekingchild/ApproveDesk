<?php

namespace App\Services;

use App\Models\RequestFieldDefinition;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use App\Models\User;

final class RequestFieldAccess
{
    public function canView(User $user, RequestFieldDefinition $field, Submission $submission): bool
    {
        return ! $field->is_sensitive
            || $submission->submitter_id === $user->id
            || $user->can('submissions.fields.sensitive');
    }

    public function canDiscoverSensitive(User $user): bool
    {
        return $user->can('submissions.fields.sensitive');
    }

    public function displayValue(?SubmissionFieldValue $value): string
    {
        if ($value === null) {
            return '—';
        }

        $resolved = match ($value->field->type->value) {
            'user' => $value->userValue?->name,
            'department' => $value->departmentValue?->name,
            default => $value->resolvedValue(),
        };

        if (is_bool($resolved)) {
            return $resolved ? 'Yes' : 'No';
        }

        if (is_array($resolved)) {
            return collect($resolved)->map(fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item))->implode(', ');
        }

        return $resolved === null || $resolved === '' ? '—' : (string) $resolved;
    }
}
