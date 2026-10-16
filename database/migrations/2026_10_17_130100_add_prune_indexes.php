<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-OPS-05: the nightly prune selects by these dates; index them so it does
 * not scan the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index('read_at', 'notifications_read_at_idx');
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->index('created_at', 'notification_deliveries_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_read_at_idx');
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('notification_deliveries_created_at_idx');
        });
    }
};
