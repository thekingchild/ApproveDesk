<?php

use App\Support\SchemaTimestamps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->string('stored_path');
            $table->string('disk')->default('local');
            $table->string('manifest_hash', 64);
            $table->string('signature', 128);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('auditor_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            SchemaTimestamps::required($table, 'starts_at');
            SchemaTimestamps::required($table, 'ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            SchemaTimestamps::required($table, 'placed_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('disposition_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('submission_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('summary');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('submission_file_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('submission_file_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('original_name');
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_file_revisions');
        Schema::dropIfExists('submission_revisions');
        Schema::dropIfExists('disposition_reviews');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('audit_export_logs');
        Schema::dropIfExists('auditor_accesses');
        Schema::dropIfExists('evidence_packs');
    }
};
