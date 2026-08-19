<?php

namespace App\Actions\WorkflowTemplates;

use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\WorkflowTemplateVersion;
use App\Services\WorkflowTemplates\WorkflowTemplateSchemaHasher;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PublishWorkflowTemplateVersionAction
{
    public function __construct(private readonly WorkflowTemplateSchemaHasher $hasher) {}

    public function execute(WorkflowTemplateVersion $version, ?string $changeSummary = null): WorkflowTemplateVersion
    {
        return DB::transaction(function () use ($version, $changeSummary): WorkflowTemplateVersion {
            /** @var WorkflowTemplateVersion $locked */
            $locked = WorkflowTemplateVersion::query()
                ->with('steps')
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($locked->status !== WorkflowTemplateVersionStatus::Draft) {
                throw new DomainException('Only draft workflow template versions can be published.');
            }

            if ($locked->steps->isEmpty()) {
                throw new DomainException('A workflow template version must define at least one step before publication.');
            }

            WorkflowTemplateVersion::query()
                ->where('workflow_template_id', $locked->workflow_template_id)
                ->where('status', WorkflowTemplateVersionStatus::Published)
                ->whereKeyNot($locked->id)
                ->get()
                ->each(function (WorkflowTemplateVersion $published): void {
                    $published->update(['status' => WorkflowTemplateVersionStatus::Retired]);
                });

            $locked->forceFill([
                'change_summary' => $changeSummary ?: ($locked->change_summary ?: 'Published workflow version.'),
                'schema_hash' => $this->hasher->hash($locked->steps),
                'published_at' => now()->toDateTimeString(),
                'status' => WorkflowTemplateVersionStatus::Published,
            ])->save();

            return $locked->refresh();
        });
    }
}
