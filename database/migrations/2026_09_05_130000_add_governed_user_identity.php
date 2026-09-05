<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('display_name_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pending_user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->string('proposed_name', 255);
            $table->string('normalized_name', 255);
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status'], 'dnc_user_status_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_image_path')->nullable()->after('avatar');
            $table->string('profile_image_mime', 32)->nullable()->after('profile_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['profile_image_path', 'profile_image_mime']);
        });
        Schema::dropIfExists('display_name_changes');
    }
};
