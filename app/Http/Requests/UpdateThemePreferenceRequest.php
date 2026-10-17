<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** A person's own application theme on Settings → Appearance (RV-LAR-09). */
class UpdateThemePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return ['theme_id' => ['nullable', 'integer']];
    }
}
