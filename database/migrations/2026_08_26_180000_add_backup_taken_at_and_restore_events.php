<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_snapshots', function (Blueprint $table): void {
            $table->timestamp('taken_at')->nullable();
            $table->foreignId('promoted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('taken_at');
        });

        DB::table('backup_snapshots')->whereNull('taken_at')->update([
            'taken_at' => DB::raw('created_at'),
        ]);

        Schema::create('backup_restore_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->nullable()->constrained('backup_snapshots')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('kind', 20);
            $table->string('snapshot_name');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_events');

        Schema::table('backup_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promoted_by_id');
            $table->dropIndex(['taken_at']);
            $table->dropColumn('taken_at');
        });
    }
};
