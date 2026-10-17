<?php

namespace App\Http\Requests;

use App\Rules\ValidOutboundUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The browser's push subscription for the signed-in person (RV-LAR-09).
 * Subscribing needs the keys; unsubscribing needs only the endpoint.
 */
class PushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A person only ever adds or removes their own subscription.
        return $this->user() !== null;
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        if ($this->isMethod('delete')) {
            return ['endpoint' => ['required', 'url', 'max:2000']];
        }

        return [
            'endpoint' => ['required', 'url', 'max:2000', new ValidOutboundUrl],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ];
    }
}
