<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('backups.destinations.manage') === true;
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'enabled' => ['sometimes', 'boolean'],
            'clear' => ['sometimes', 'boolean'],
            ...StoreBackupDestinationRequest::credentialRules(),
        ];
    }
}
