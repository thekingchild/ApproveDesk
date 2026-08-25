<?php

use App\Services\WorkflowTemplates\BackfillWorkflowTemplateVersions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('schema_hash', 64)->nullable();
            $table->string('change_summary', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_template_id', 'version']);
            $table->index(['workflow_template_id', 'status']);
        });

        Schema::table('workflow_template_steps', function (Blueprint $table): void {
            $table->foreignId('template_version_id')
                ->nullable()
                ->after('template_id')
                ->constrained('workflow_template_versions')
                ->cascadeOnDelete();
        });

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->foreignId('template_version_id')
                ->nullable()
                ->after('template_id')
                ->constrained('workflow_template_versions')
                ->restrictOnDelete();
        });

        app(BackfillWorkflowTemplateVersions::class)->execute();

        if (! Schema::hasIndex('workflow_template_steps', 'wts_template_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->index('template_id', 'wts_template_id_index');
            });
        }

        Schema::table('workflow_template_steps', function (Blueprint $table): void {
            $table->dropUnique(['template_id', 'step_order']);
            $table->unique(['template_version_id', 'step_order']);
        });

        if (! Schema::hasIndex('workflow_template_steps', 'wts_template_version_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->index('template_version_id', 'wts_template_version_id_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('workflow_template_steps', 'wts_template_version_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->index('template_version_id', 'wts_template_version_id_index');
            });
        }

        Schema::table('workflow_template_steps', function (Blueprint $table): void {
            $table->dropUnique(['template_version_id', 'step_order']);
            $table->unique(['template_id', 'step_order']);
            $table->dropForeign(['template_version_id']);
        });

        if (Schema::hasIndex('workflow_template_steps', 'wts_template_version_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->dropIndex('wts_template_version_id_index');
            });
        }

        Schema::table('workflow_template_steps', function (Blueprint $table): void {
            $table->dropColumn('template_version_id');
        });

        if (Schema::hasIndex('workflow_template_steps', 'wts_template_id_index')) {
            Schema::table('workflow_template_steps', function (Blueprint $table): void {
                $table->dropIndex('wts_template_id_index');
            });
        }

        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_version_id');
        });

        Schema::dropIfExists('workflow_template_versions');
    }
};
