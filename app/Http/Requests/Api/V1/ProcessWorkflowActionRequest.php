<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkflowActionType;
use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessWorkflowActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Submission|null $submission */
        $submission = $this->route('submission');

        return $submission !== null
            && $this->user()->tokenCan('approvals:act')
            && $this->user()->can('view', $submission);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([
                WorkflowActionType::Approve->value,
                WorkflowActionType::Reject->value,
                WorkflowActionType::Return->value,
                WorkflowActionType::RequestInformation->value,
                WorkflowActionType::Escalate->value,
                WorkflowActionType::Comment->value,
            ])],
            'comment' => ['nullable', 'string', 'max:5000'],
            'step_id' => ['nullable', 'integer'],
        ];
    }
}
