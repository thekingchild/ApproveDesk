<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 100);
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('primary_light', 7);
            $table->string('primary_dark', 7);
            $table->string('foreground_light', 7);
            $table->string('foreground_dark', 7);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'archived_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('theme_id')->nullable()->after('avatar')->constrained('themes')->nullOnDelete();
        });

        $now = now();
        DB::table('themes')->insert([
            ['key' => 'approvedesk', 'name' => 'ApproveDesk', 'is_builtin' => true, 'is_enabled' => true, 'is_default' => true, 'primary_light' => '#2563EB', 'primary_dark' => '#60A5FA', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#172554', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ocean', 'name' => 'Ocean', 'is_builtin' => true, 'is_enabled' => true, 'is_default' => false, 'primary_light' => '#0369A1', 'primary_dark' => '#38BDF8', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#082F49', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'orchid', 'name' => 'Orchid', 'is_builtin' => true, 'is_enabled' => true, 'is_default' => false, 'primary_light' => '#7E22CE', 'primary_dark' => '#C084FC', 'foreground_light' => '#FFFFFF', 'foreground_dark' => '#3B0764', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('theme_id'));
        Schema::dropIfExists('themes');
    }
};
