<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;

class ShowSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $submission = $this->route('submission');

        return $submission instanceof Submission
            && $this->user()?->tokenCan('submissions:read') === true
            && $this->user()->can('view', $submission);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
