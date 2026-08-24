<?php

namespace App\Services;

use App\Actions\Privacy\LogSensitiveFieldAccessAction;
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

    public function reveal(User $user, RequestFieldDefinition $field, Submission $submission, string $context = 'view'): ?string
    {
        if (! $this->canView($user, $field, $submission)) {
            return null;
        }

        if ($field->is_sensitive) {
            app(LogSensitiveFieldAccessAction::class)->execute($user, $submission, $field, $context);
        }

        $value = $submission->relationLoaded('fieldValues')
            ? $submission->fieldValues->firstWhere('request_field_definition_id', $field->id)
            : $submission->fieldValues()->where('request_field_definition_id', $field->id)->first();

        return $this->displayValue($value);
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
