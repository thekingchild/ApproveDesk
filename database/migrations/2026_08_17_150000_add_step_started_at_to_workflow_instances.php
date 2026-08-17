<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->timestamp('step_started_at')->nullable()->after('started_at')->index();
        });

        DB::table('workflow_instances')
            ->whereNull('step_started_at')
            ->update(['step_started_at' => DB::raw('started_at')]);
    }

    public function down(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropIndex(['step_started_at']);
            $table->dropColumn('step_started_at');
        });
    }
};
