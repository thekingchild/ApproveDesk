<?php

namespace App\Http\Requests;

use App\Models\IntegrationConnector;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The credential form on Administration → Integrations, one connector at a time. */
class UpdateIntegrationCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connector = $this->route('connector');

        return $connector instanceof IntegrationConnector && $this->user()?->can('update', $connector) === true;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        $rules = ['clear' => ['sometimes', 'boolean']];

        $connector = $this->route('connector');
        foreach ($connector instanceof IntegrationConnector ? $connector->type->credentialFields() : [] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:4096'];
        }

        return $rules;
    }
}
