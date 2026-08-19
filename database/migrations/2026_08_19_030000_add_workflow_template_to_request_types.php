<?php

use App\Services\RequestTypes\BackfillRequestTypeWorkflowAssignments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_types', function (Blueprint $table): void {
            $table->foreignId('workflow_template_id')
                ->nullable()
                ->after('is_system')
                ->constrained('workflow_templates')
                ->nullOnDelete();
        });

        app(BackfillRequestTypeWorkflowAssignments::class)->execute();
    }

    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workflow_template_id');
        });
    }
};
