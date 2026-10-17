<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the dashboard, which runs on every sign-in (RV-LAR-20):
 * - a requester's tiles count their own requests grouped by status;
 * - "Recent requests" orders by updated_at, organisation-wide or per requester.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->index(['submitter_id', 'status'], 'submissions_submitter_status_idx');
            $table->index(['submitter_id', 'updated_at'], 'submissions_submitter_updated_idx');
            $table->index('updated_at', 'submissions_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropIndex('submissions_submitter_status_idx');
            $table->dropIndex('submissions_submitter_updated_idx');
            $table->dropIndex('submissions_updated_at_idx');
        });
    }
};
