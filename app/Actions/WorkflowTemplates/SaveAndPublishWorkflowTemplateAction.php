<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Publish version" on the builder and the Workflow templates page: save the
 * edits and publish the resulting draft as one step. If publishing is refused
 * (for example an unreachable step) the edits are not saved either, so a
 * failed publish never leaves a half-finished draft behind (RV-ARC-07).
 */
final class SaveAndPublishWorkflowTemplateAction
{
    public function __construct(
        private readonly UpdateWorkflowTemplateAction $update,
        private readonly PublishWorkflowTemplateVersionAction $publish,
    ) {}

    public function execute(WorkflowTemplate $template, WorkflowTemplateData $data, User $editor, ?string $expectedToken = null): ?WorkflowTemplateVersion
    {
        // Reachable from two screens: check here, not only on the page (ADR 0008).
        Gate::forUser($editor)->authorize('update', $template);
        Gate::forUser($editor)->authorize('publish', $template);

        return DB::transaction(function () use ($template, $data, $editor, $expectedToken): ?WorkflowTemplateVersion {
            $saved = $this->update->execute($template, $data, $editor, $expectedToken);

            // A template no request has used yet is published by the save itself.
            if ($saved->draftVersion !== null) {
                $this->publish->execute($saved->draftVersion, $data->changeSummary);
            }

            return $saved->fresh('publishedVersion')?->publishedVersion;
        });
    }
}
