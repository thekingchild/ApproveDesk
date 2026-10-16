<?php

use App\Support\SchemaTimestamps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_snapshots', function (Blueprint $table) {
            $table->id();
            SchemaTimestamps::required($table, 'recorded_at');
            $table->unsignedInteger('pending_jobs')->default(0);
            $table->unsignedInteger('failed_jobs')->default(0);
            $table->unsignedInteger('dead_letter_webhooks')->default(0);
            $table->unsignedInteger('dead_letter_integrations')->default(0);
            $table->unsignedInteger('failed_notifications')->default(0);
            $table->unsignedInteger('stuck_workflows')->default(0);
            $table->unsignedInteger('oldest_pending_seconds')->nullable();

            $table->index('recorded_at');
        });

        Schema::create('ops_backup_verifications', function (Blueprint $table) {
            $table->id();
            SchemaTimestamps::required($table, 'verified_at');
            $table->string('source', 20);
            $table->string('path')->nullable();
            $table->boolean('ok');
            $table->json('table_counts');
            $table->json('issues');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_backup_verifications');
        Schema::dropIfExists('ops_snapshots');
    }
};
