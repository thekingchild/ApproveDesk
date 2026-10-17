<?php

namespace App\Actions\RequestTypes;

use App\DTOs\RequestFieldData;
use App\Models\RequestType;
use App\Models\User;

final class CloneRequestTypeAction
{
    public function __construct(private readonly SaveRequestTypeDraftAction $saveDraft) {}

    public function execute(RequestType $source, User $actor): RequestType
    {
        $version = $source->versions()->with('fields')->firstOrFail();
        // Every flag, so a copy keeps search, reporting and privacy settings.
        $fields = array_values($version->fields->map(RequestFieldData::fromDefinition(...))->all());

        $copy = $this->saveDraft->execute(null, $source->name.' copy', $source->description, $fields, $actor)->requestType;
        $copy->update(['workflow_template_id' => $source->workflow_template_id]);

        return $copy;
    }
}
