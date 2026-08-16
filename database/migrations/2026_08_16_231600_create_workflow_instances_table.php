<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained('submissions')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('workflow_templates');
            $table->foreignId('current_step_id')->nullable()
                ->constrained('workflow_template_steps')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('current_step_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
