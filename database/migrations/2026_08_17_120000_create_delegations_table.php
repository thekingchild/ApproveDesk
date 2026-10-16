<?php

use App\Support\SchemaTimestamps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('workflow_template_steps');
            $table->foreignId('delegator_id')->constrained('users');
            $table->foreignId('delegate_id')->constrained('users');
            SchemaTimestamps::required($table, 'starts_at');
            SchemaTimestamps::required($table, 'expires_at');
            $table->timestamps();

            $table->index(['instance_id', 'step_id', 'delegate_id', 'expires_at'], 'delegations_active_delegate_index');
            $table->index(['instance_id', 'step_id', 'delegator_id', 'expires_at'], 'delegations_active_delegator_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
