<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_field_definitions', function (Blueprint $table): void {
            $table->boolean('is_searchable')->default(false)->after('is_required');
            $table->boolean('is_filterable')->default(false)->after('is_searchable');
            $table->boolean('is_reportable')->default(false)->after('is_filterable');
            $table->boolean('is_sensitive')->default(false)->after('is_reportable');
            $table->boolean('is_approver_summary')->default(false)->after('is_sensitive');
        });

        Schema::create('submission_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'request_type_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_saved_views');

        Schema::table('request_field_definitions', function (Blueprint $table): void {
            $table->dropColumn([
                'is_searchable', 'is_filterable', 'is_reportable', 'is_sensitive', 'is_approver_summary',
            ]);
        });
    }
};
