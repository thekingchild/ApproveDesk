<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('teams_user_id', 64)->nullable()->unique()->after('phone');
            $table->string('slack_user_id', 64)->nullable()->unique()->after('teams_user_id');
            $table->string('telegram_user_id', 64)->nullable()->unique()->after('slack_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['teams_user_id']);
            $table->dropUnique(['slack_user_id']);
            $table->dropUnique(['telegram_user_id']);
            $table->dropColumn(['teams_user_id', 'slack_user_id', 'telegram_user_id']);
        });
    }
};
