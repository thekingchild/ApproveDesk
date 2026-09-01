<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_reference_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 80)->unique();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_reference_counters');
    }
};
