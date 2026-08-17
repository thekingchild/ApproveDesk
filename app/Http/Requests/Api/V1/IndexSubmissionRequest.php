<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tokenCan('submissions:read') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(SubmissionStatus::class)],
            'type' => ['nullable', Rule::enum(SubmissionType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
