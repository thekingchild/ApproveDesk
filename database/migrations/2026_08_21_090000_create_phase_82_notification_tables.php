<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40)->unique();
            $table->string('subject', 180);
            $table->text('body');
            $table->boolean('is_custom')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 512);
            $table->string('language', 16);
            $table->string('status', 32);
            $table->string('category', 32)->nullable();
            $table->json('components')->nullable();
            $table->unsignedSmallInteger('placeholder_count')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language']);
        });

        Schema::create('whatsapp_notification_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40)->unique();
            $table->string('template_name', 512);
            $table->string('language', 16);
            $table->json('bindings');
            $table->boolean('is_healthy')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('channel', 32);
            $table->string('status', 24);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->timestamps();

            $table->unique(['user_id', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('whatsapp_notification_maps');
        Schema::dropIfExists('whatsapp_message_templates');
        Schema::dropIfExists('notification_email_templates');
    }
};
