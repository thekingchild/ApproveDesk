<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Submission|null $submission */
        $submission = $this->route('submission');

        return $submission !== null
            && $this->user()->tokenCan('submissions:write')
            && $this->user()->can('submit', $submission);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var Submission|null $submission */
        $submission = $this->route('submission');

        // Answering an information request needs an answer (RV-BUG-21).
        return [
            'comment' => [
                Rule::requiredIf($submission?->status === SubmissionStatus::AwaitingInformation),
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}
