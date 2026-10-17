<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Send a test email from Settings → System (RV-LAR-09). */
class TestSystemMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system_settings.manage') === true;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255']];
    }
}
