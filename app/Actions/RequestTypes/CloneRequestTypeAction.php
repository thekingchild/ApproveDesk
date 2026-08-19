<?php

namespace App\Actions\RequestTypes;

use App\Models\RequestType;
use App\Models\User;

final class CloneRequestTypeAction
{
    public function __construct(private readonly SaveRequestTypeDraftAction $saveDraft) {}

    public function execute(RequestType $source, User $actor): RequestType
    {
        $version = $source->versions()->with('fields')->firstOrFail();
        $fields = $version->fields->map(fn ($field): array => [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type->value,
            'is_required' => $field->is_required,
            'configuration' => $field->configuration,
        ])->all();

        $copy = $this->saveDraft->execute(null, $source->name.' copy', $source->description, $fields, $actor)->requestType;
        $copy->update(['workflow_template_id' => $source->workflow_template_id]);

        return $copy;
    }
}
