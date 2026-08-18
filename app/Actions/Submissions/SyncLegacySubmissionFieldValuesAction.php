<?php

namespace App\Actions\Submissions;

use App\Models\RequestType;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;

final class SyncLegacySubmissionFieldValuesAction
{
    public function execute(Submission $submission): void
    {
        $submission->loadMissing('requestTypeVersion.requestType', 'requestTypeVersion.fields');

        if ($submission->requestTypeVersion->requestType->slug !== RequestType::GENERAL_SLUG) {
            return;
        }

        $values = [
            'type' => ['string_value' => $submission->type->value],
            'title' => ['string_value' => $submission->title],
            'body' => ['text_value' => $submission->body],
            'priority' => ['string_value' => $submission->priority->value],
            'department' => ['department_value_id' => $submission->department_id],
            'due_date' => ['date_value' => $submission->due_date?->toDateString()],
        ];

        foreach ($submission->requestTypeVersion->fields as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $typedValue = $values[$field->key];

            if (collect($typedValue)->first() === null) {
                SubmissionFieldValue::query()
                    ->where('submission_id', $submission->id)
                    ->where('request_field_definition_id', $field->id)
                    ->delete();

                continue;
            }

            SubmissionFieldValue::query()->updateOrCreate(
                [
                    'submission_id' => $submission->id,
                    'request_field_definition_id' => $field->id,
                ],
                [
                    ...array_fill_keys(SubmissionFieldValue::VALUE_COLUMNS, null),
                    ...$typedValue,
                ],
            );
        }
    }
}
