<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RV-ENG-03: record whose seat a delegate decided for, so a delegator and
 * their delegate cannot both count toward an All or Quorum step. Nullable;
 * existing rows keep their meaning (the actor's own seat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->foreignId('on_behalf_of_id')
                ->nullable()
                ->after('actor_id')
                ->constrained('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('on_behalf_of_id');
        });
    }
};
