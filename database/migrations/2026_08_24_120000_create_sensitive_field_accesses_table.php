<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitive_field_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_field_definition_id')->constrained()->cascadeOnDelete();
            $table->string('context', 32);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'submission_id', 'request_field_definition_id', 'context', 'created_at'], 'sensitive_field_access_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_field_accesses');
    }
};
