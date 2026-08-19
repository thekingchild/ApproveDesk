<?php

namespace App\Http\Requests;

use App\Enums\AnalyticsExportFormat;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyticsExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.view') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', Rule::in(AnalyticsService::PERIODS)],
            'format' => ['required', Rule::enum(AnalyticsExportFormat::class)],
            'request_type' => ['nullable', 'integer', 'exists:request_types,id'],
        ];
    }
}
