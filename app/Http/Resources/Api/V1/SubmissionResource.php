<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Submission;
use App\Services\RichTextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Submission */
class SubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'type' => $this->type->value,
            'request_type_version_id' => $this->request_type_version_id,
            'title' => $this->title,
            'body' => app(RichTextSanitizer::class)->sanitize((string) $this->body),
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'due_date' => $this->due_date?->toDateString(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
            'submitter' => $this->whenLoaded('submitter', fn (): array => [
                'id' => $this->submitter->id,
                'name' => $this->submitter->name,
            ]),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'request_type' => $this->whenLoaded('requestTypeVersion', fn (): array => [
                'id' => $this->requestTypeVersion->requestType->id,
                'name' => $this->requestTypeVersion->requestType->name,
                'slug' => $this->requestTypeVersion->requestType->slug,
                'version' => $this->requestTypeVersion->version,
            ]),
            'workflow' => new WorkflowInstanceResource($this->whenLoaded('workflowInstance')),
        ];
    }
}
