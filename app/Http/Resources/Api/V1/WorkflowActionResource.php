<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WorkflowAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkflowAction */
class WorkflowActionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toAtomString(),
            'actor' => $this->whenLoaded('actor', fn (): array => [
                'id' => $this->actor?->id,
                'name' => $this->actor?->name,
            ]),
            'step' => $this->whenLoaded('step', fn (): array => [
                'id' => $this->step->id,
                'name' => $this->step->name,
            ]),
        ];
    }
}
