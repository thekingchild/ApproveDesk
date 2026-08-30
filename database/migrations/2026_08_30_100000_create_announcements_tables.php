<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->text('message');
            $table->string('audience', 20);
            $table->json('role_ids')->nullable();
            $table->string('display_mode', 20);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::create('announcement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_displayed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_receipts');
        Schema::dropIfExists('announcements');
    }
};
