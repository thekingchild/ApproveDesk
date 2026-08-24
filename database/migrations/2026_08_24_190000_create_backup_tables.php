<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 20);
            $table->text('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('destination_id')->constrained('backup_destinations')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('path')->nullable();
            $table->string('remote_id')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('retained_until')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status']);
            $table->index('retained_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_snapshots');
        Schema::dropIfExists('backup_destinations');
    }
};
