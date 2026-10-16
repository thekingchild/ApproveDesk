<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionType;
use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->tokenCan('submissions:write')
            && $this->user()->can('create', Submission::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(SubmissionType::class)],
            'title' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:65535'],
            'priority' => ['nullable', Rule::enum(SubmissionPriority::class)],
            // Department drives department-manager routing; requesters may only use
            // their own, as on the web form (RV-SEC-12).
            'department_id' => ['nullable', 'integer', Rule::in(array_filter([$this->user()->department_id]))],
            'due_date' => ['nullable', 'date', 'after:today'],
            'submit' => ['nullable', 'boolean'],
        ];
    }
}
