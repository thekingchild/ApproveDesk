<?php

namespace App\Actions\RequestTypes;

use App\Actions\WorkflowTemplates\SaveWorkflowSelectionRulesAction;
use App\DTOs\RequestTypeData;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Save draft / Publish in the request-type builder: the form, its workflow
 * assignment and selection rules, and optionally publishing, as one
 * transaction. A refused selection rule or schema used to leave the draft
 * saved, and retrying a new type created it a second time (RV-ARC-05).
 */
final class SaveRequestTypeAction
{
    public function __construct(
        private readonly SaveRequestTypeDraftAction $saveDraft,
        private readonly AssignRequestTypeWorkflowAction $assignWorkflow,
        private readonly SaveWorkflowSelectionRulesAction $saveRules,
        private readonly PublishRequestTypeVersionAction $publish,
    ) {}

    public function execute(?RequestType $type, RequestTypeData $data, User $actor, bool $publish = false): RequestTypeVersion
    {
        $gate = Gate::forUser($actor);
        $type === null ? $gate->authorize('create', RequestType::class) : $gate->authorize('update', $type);
        if ($publish) {
            $gate->authorize('publish', RequestType::class);
        }

        return DB::transaction(function () use ($type, $data, $actor, $publish): RequestTypeVersion {
            $version = $this->saveDraft->execute($type, $data->name, $data->description, $data->fields, $actor);
            $saved = $version->requestType;

            // Only an actual change needs the assign permission.
            $current = $saved->workflow_template_id === null ? null : (int) $saved->workflow_template_id;
            if ($current !== $data->workflowTemplateId) {
                $this->assignWorkflow->execute($saved, $data->workflowTemplateId, $actor);
            }

            $this->saveRules->execute($saved, $data->selectionRules, $actor);

            return $publish ? $this->publish->execute($version) : $version;
        });
    }
}
