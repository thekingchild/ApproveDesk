<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-DATA-06: an attachment removed after the request was submitted is kept
 * (soft-deleted, with who removed it) so approvers' evidence survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_files', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('removed_by')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('submission_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropSoftDeletes();
        });
    }
};
