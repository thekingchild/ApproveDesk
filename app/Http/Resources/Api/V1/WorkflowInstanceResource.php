<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WorkflowInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkflowInstance */
class WorkflowInstanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'started_at' => $this->started_at->toAtomString(),
            'step_started_at' => $this->step_started_at?->toAtomString(),
            'completed_at' => $this->completed_at?->toAtomString(),
            'current_step' => $this->whenLoaded('currentStep', fn (): ?array => $this->currentStep === null ? null : [
                'id' => $this->currentStep->id,
                'name' => $this->currentStep->name,
                'type' => $this->currentStep->step_type->value,
            ]),
            'actions' => WorkflowActionResource::collection($this->whenLoaded('actions')),
        ];
    }
}
