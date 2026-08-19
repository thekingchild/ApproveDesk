<?php

use App\Enums\GroupCompletion;
use App\Enums\StepType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('workflow_template_steps', function (Blueprint $table) {
            $table->string('completion_rule')->nullable()->after('quorum_count');
            $table->string('timeout_calendar')->nullable()->after('timeout_action');
            $table->json('assignee_config')->nullable()->after('assignee_ids');
        });

        Schema::table('workflow_template_versions', function (Blueprint $table) {
            $table->json('outcomes')->nullable()->after('change_summary');
        });

        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->string('outcome_key')->nullable()->after('action');
        });

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->text('selection_explanation')->nullable()->after('template_version_id');
        });

        Schema::create('workflow_selection_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_template_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->json('conditions');
            $table->timestamps();

            $table->index(['request_type_id', 'is_active', 'priority']);
        });

        $this->backfillCompletionRules();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_selection_rules');

        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropColumn('selection_explanation');
        });

        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->dropColumn('outcome_key');
        });

        Schema::table('workflow_template_versions', function (Blueprint $table) {
            $table->dropColumn('outcomes');
        });

        Schema::table('workflow_template_steps', function (Blueprint $table) {
            $table->dropColumn(['completion_rule', 'timeout_calendar', 'assignee_config']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }

    private function backfillCompletionRules(): void
    {
        $map = [
            StepType::Sequential->value => GroupCompletion::FirstResponse->value,
            StepType::Parallel->value => GroupCompletion::All->value,
            StepType::Quorum->value => GroupCompletion::Quorum->value,
        ];

        foreach ($map as $stepType => $rule) {
            DB::table('workflow_template_steps')
                ->where('step_type', $stepType)
                ->whereNull('completion_rule')
                ->update(['completion_rule' => $rule]);
        }

        DB::table('workflow_template_steps')
            ->whereNull('timeout_calendar')
            ->update(['timeout_calendar' => 'elapsed']);
    }
};
