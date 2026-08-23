<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RequestType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestType */
class RequestTypeSchemaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $version = $this->publishedVersion;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'version' => $version?->version,
            'fields' => $version?->fields
                ->sortBy('position')
                ->values()
                ->map(fn ($field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type->value,
                    'required' => $field->is_required,
                    'sensitive' => $field->is_sensitive,
                    'options' => $field->configuration['options'] ?? null,
                ])
                ->all() ?? [],
        ];
    }
}
