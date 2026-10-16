<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RV-BUG-27: an Idempotency-Key is claimed by inserting its row before the
 * request runs; the stored status stays empty until the response is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('status_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('api_idempotency_keys')->whereNull('status_code')->delete();

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('status_code')->nullable(false)->change();
        });
    }
};
