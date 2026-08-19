<?php

namespace Database\Factories;

use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowTemplateVersion> */
class WorkflowTemplateVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workflow_template_id' => WorkflowTemplate::factory(),
            'version' => 1,
            'status' => WorkflowTemplateVersionStatus::Draft,
            'schema_hash' => null,
            'change_summary' => null,
            'created_by' => User::factory(),
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkflowTemplateVersionStatus::Published,
            'published_at' => now(),
            'schema_hash' => hash('sha256', '[]'),
        ]);
    }
}
