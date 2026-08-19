<?php

namespace App\Actions\RequestTypes;

use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use App\Services\RequestTypes\RequestSchemaCompiler;
use Illuminate\Support\Facades\DB;

final class StoreSubmissionFieldValuesAction
{
    public function __construct(private readonly RequestSchemaCompiler $compiler) {}

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function execute(Submission $submission, array $values, bool $enforceRequired = true): array
    {
        $version = $submission->requestTypeVersion()->with('fields')->firstOrFail();
        $values = $this->compiler->withCalculatedValues($version, $values);
        $validated = $this->compiler->validate($version, $values, $enforceRequired);
        $validated = $this->compiler->withCalculatedValues($version, $validated);

        DB::transaction(function () use ($submission, $version, $validated): void {
            foreach ($version->fields as $field) {
                if (! $this->compiler->isVisible($field, $validated)) {
                    SubmissionFieldValue::query()->where('submission_id', $submission->id)->where('request_field_definition_id', $field->id)->delete();

                    continue;
                }

                $attributes = $this->compiler->typedAttributes($field, $validated);

                if ($attributes === []) {
                    SubmissionFieldValue::query()->where('submission_id', $submission->id)->where('request_field_definition_id', $field->id)->delete();

                    continue;
                }

                SubmissionFieldValue::query()->updateOrCreate(
                    ['submission_id' => $submission->id, 'request_field_definition_id' => $field->id],
                    [...array_fill_keys(SubmissionFieldValue::VALUE_COLUMNS, null), ...$attributes],
                );
            }
        });

        return $validated;
    }
}
