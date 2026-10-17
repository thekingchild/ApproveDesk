<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-BUG-19: the daily reminder cap counts reminder actions, not recipients.
 * One "Remind approver" click writes one row per recipient; batch_id groups
 * them. Older rows stay NULL (append-only) and count as one action each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_nudges', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable()->after('step_id');
        });
    }

    public function down(): void
    {
        Schema::table('submission_nudges', function (Blueprint $table): void {
            $table->dropColumn('batch_id');
        });
    }
};
