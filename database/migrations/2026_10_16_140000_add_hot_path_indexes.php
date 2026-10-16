<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 indexes for queries that run on every page or per approval:
 * - step completion / review-round lookups filter workflow_actions by
 *   (instance_id, step_id, action);
 * - the unread-notification badge filters by read_at on every page;
 * - analytics and request lists filter or order submissions by created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->index(['instance_id', 'step_id', 'action'], 'wf_actions_instance_step_action_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_idx');
        });

        Schema::table('submissions', function (Blueprint $table): void {
            $table->index('created_at', 'submissions_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->dropIndex('wf_actions_instance_step_action_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_unread_idx');
        });

        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropIndex('submissions_created_at_idx');
        });
    }
};
