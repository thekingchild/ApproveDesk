<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;

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
        return [];
    }
}
