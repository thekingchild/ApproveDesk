<?php

namespace Database\Factories;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplateStep>
 */
class WorkflowTemplateStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_id' => WorkflowTemplate::factory(),
            'template_version_id' => null,
            'step_order' => 1,
            'name' => 'Approval',
            'step_type' => StepType::Sequential,
            'quorum_count' => null,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => [],
            'conditions' => null,
            'timeout_hours' => null,
            'timeout_action' => null,
            'allow_delegation' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (WorkflowTemplateStep $step): void {
            if ($step->template_version_id) {
                return;
            }

            $template = $step->template_id
                ? WorkflowTemplate::query()->find($step->template_id)
                : WorkflowTemplate::factory()->create();

            if ($template === null) {
                return;
            }

            $version = $this->mutableFactoryVersion($template);
            $step->template_id = $template->id;
            $step->template_version_id = $version->id;
        });
    }

    public function forTemplate(WorkflowTemplate $template, int $order): static
    {
        return $this->forVersion($this->mutableFactoryVersion($template), $order)
            ->afterCreating(function () use ($template): void {
                $this->publishFactoryVersion($template);
            });
    }

    private function mutableFactoryVersion(WorkflowTemplate $template): WorkflowTemplateVersion
    {
        $template->unsetRelation('publishedVersion');
        $template->unsetRelation('draftVersion');
        $template->unsetRelation('versions');

        if ($template->draftVersion !== null) {
            return $template->draftVersion;
        }

        if ($template->publishedVersion !== null) {
            $published = $template->publishedVersion;
            $published->updateQuietly(['status' => WorkflowTemplateVersionStatus::Draft]);
            $draft = $published->fresh();
            $template->unsetRelation('publishedVersion');
            $template->setRelation('draftVersion', $draft);

            return $draft;
        }

        $version = $template->versions()->create([
            'version' => (int) ($template->versions()->max('version') ?? 0) + 1,
            'status' => WorkflowTemplateVersionStatus::Draft,
            'created_by' => $template->created_by,
        ]);
        $template->setRelation('draftVersion', $version);

        return $version;
    }

    private function publishFactoryVersion(WorkflowTemplate $template): void
    {
        $template->unsetRelation('draftVersion');
        $template->unsetRelation('publishedVersion');
        $draft = $template->draftVersion;
        if ($draft === null) {
            return;
        }

        $draft->updateQuietly([
            'status' => WorkflowTemplateVersionStatus::Published,
            'published_at' => now(),
        ]);
        $template->unsetRelation('draftVersion');
        $template->unsetRelation('publishedVersion');
        $template->setRelation('publishedVersion', $draft->fresh());
    }

    public function forVersion(WorkflowTemplateVersion $version, int $order): static
    {
        return $this->state(fn () => [
            'template_id' => $version->workflow_template_id,
            'template_version_id' => $version->id,
            'step_order' => $order,
        ]);
    }
}
