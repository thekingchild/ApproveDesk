<?php

use App\Support\SchemaTimestamps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('workflow_template_steps');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 20);
            $table->text('comment')->nullable();
            $table->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            SchemaTimestamps::required($table, 'created_at');

            $table->index(['instance_id', 'actor_id']);
            $table->index(['step_id', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
    }
};
