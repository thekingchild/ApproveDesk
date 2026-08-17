<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->string('action', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable(false)->change();
            $table->string('action', 20)->change();
        });
    }
};
