<?php

namespace App\Http\Requests;

use App\Rules\ValidOutboundUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBackupDestinationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'in:s3,gdrive'],
            'enabled' => ['sometimes', 'boolean'],
            ...self::credentialRules(),
        ];
    }

    /**
     * Rules for the credential fields, shared with updates (RV-LAR-08).
     *
     * @return array<string, list<string|ValidationRule>>
     */
    public static function credentialRules(): array
    {
        return [
            'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:80'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:255', new ValidOutboundUrl([
                'allow_private' => (bool) config('backups.allow_private_s3_endpoint'),
                'allow_nonstandard_port' => (bool) config('backups.allow_private_s3_endpoint'),
            ])],
            'path_style' => ['nullable', 'string', 'max:1'],
            'prefix' => ['nullable', 'string', 'max:120'],
            'folder_id' => ['nullable', 'string', 'max:255'],
            'refresh_token' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
