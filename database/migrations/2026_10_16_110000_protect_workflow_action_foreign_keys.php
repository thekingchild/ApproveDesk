<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-ENG-06: workflow_actions is append-only, so the database must never
 * rewrite or delete its rows when a user or instance is deleted. Replace
 * nullOnDelete (delegated_to) and cascadeOnDelete (instance_id) with
 * restrict; deleting a referenced user or instance now fails loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->dropForeign(['delegated_to']);
            $table->dropForeign(['instance_id']);
        });

        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->foreign('delegated_to')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('instance_id')->references('id')->on('workflow_instances')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->dropForeign(['delegated_to']);
            $table->dropForeign(['instance_id']);
        });

        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->foreign('delegated_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
        });
    }
};
