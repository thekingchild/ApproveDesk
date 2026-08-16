<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_template_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order');
            $table->string('name');
            $table->string('step_type', 20)->default('sequential');
            $table->unsignedTinyInteger('quorum_count')->nullable();
            $table->string('assignee_type', 20)->default('user');
            $table->json('assignee_ids');
            $table->json('conditions')->nullable();
            $table->unsignedSmallInteger('timeout_hours')->nullable();
            $table->string('timeout_action', 20)->nullable();
            $table->boolean('allow_delegation')->default(true);
            $table->timestamps();

            $table->unique(['template_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_template_steps');
    }
};
