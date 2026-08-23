<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connectors', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_request_at')->nullable();
            $table->unsignedInteger('min_interval_seconds')->default(1);
            $table->timestamps();
        });

        Schema::create('integration_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connector_id')->constrained('integration_connectors')->cascadeOnDelete();
            $table->foreignId('request_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('name');
            $table->json('configuration')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('integration_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('method', 10);
            $table->string('path');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status_code');
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('integration_deliveries');
        Schema::dropIfExists('integration_actions');
        Schema::dropIfExists('integration_connectors');
    }
};
