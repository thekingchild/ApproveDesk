<?php

namespace App\Services\WorkflowTemplates;

use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;
use App\Support\WorkflowTemplateSchemaHasher;

final class BackfillWorkflowTemplateVersions
{
    public function __construct(private readonly WorkflowTemplateSchemaHasher $hasher) {}

    public function execute(): void
    {
        WorkflowTemplate::query()->orderBy('id')->each(function (WorkflowTemplate $template): void {
            if ($template->versions()->exists()) {
                return;
            }

            $version = WorkflowTemplateVersion::query()->create([
                'workflow_template_id' => $template->id,
                'version' => 1,
                'status' => WorkflowTemplateVersionStatus::Draft,
                'change_summary' => 'Backfilled from the pre-versioned template.',
                'created_by' => $template->created_by,
            ]);

            WorkflowTemplateStep::query()
                ->where('template_id', $template->id)
                ->whereNull('template_version_id')
                ->update(['template_version_id' => $version->id]);

            $version->unsetRelation('steps');
            $version->updateQuietly([
                'status' => WorkflowTemplateVersionStatus::Published,
                'published_at' => $template->created_at ?? now(),
                'schema_hash' => $this->hasher->hash($version->steps()->get()),
            ]);

            WorkflowInstance::query()
                ->where('template_id', $template->id)
                ->whereNull('template_version_id')
                ->update(['template_version_id' => $version->id]);
        });
    }
}
