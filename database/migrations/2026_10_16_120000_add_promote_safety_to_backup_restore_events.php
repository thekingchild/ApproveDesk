<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-DATA-03: record what a promote discarded and which safety backup was
 * taken just before it. Plain columns, no foreign key: the snapshots table
 * itself is replaced during a promote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_restore_events', function (Blueprint $table): void {
            $table->unsignedInteger('discarded_workflow_actions')->nullable()->after('snapshot_name');
            $table->unsignedBigInteger('safety_snapshot_id')->nullable()->after('discarded_workflow_actions');
        });
    }

    public function down(): void
    {
        Schema::table('backup_restore_events', function (Blueprint $table): void {
            $table->dropColumn(['discarded_workflow_actions', 'safety_snapshot_id']);
        });
    }
};
